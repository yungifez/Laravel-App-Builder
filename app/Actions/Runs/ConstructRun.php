<?php

namespace App\Actions\Runs;

use App\Actions\Features\RequestVerification;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\Exceptions\CannotGenerateFeature;
use App\Models\Run;
use App\Runs\ConstructionDriverManager;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\RunLease;
use App\Runs\ToolExecutor;
use App\Runs\ToolSession;
use Illuminate\Support\Facades\DB;

class ConstructRun
{
    /**
     * What the owner can do when a run stops for a decision.
     *
     * @var list<string>
     */
    public const DECISION_CHOICES = ['revise_request', 'use_stronger_model', 'involve_a_person'];

    public function __construct(
        private TransitionRun $transitionRun,
        private PrepareRunWorkspace $prepareRunWorkspace,
        private ConstructionDriverManager $drivers,
        private ToolExecutor $toolExecutor,
        private ExtractCandidateChange $extractCandidateChange,
        private RequestVerification $requestVerification,
        private CancelRun $cancelRun,
        private FailRun $failRun,
    ) {}

    /**
     * Take the run from wherever it is up to verification, as the lease holder.
     *
     * A resumed run picks up from its recorded state; the driver's tool calls
     * replay from the journal, so nothing is done twice.
     */
    public function handle(Run $run, RunLease $lease): void
    {
        try {
            $this->advance($run, $lease);
        } catch (RunCancelled) {
            $this->cancelRun->finish($run);
        } catch (LeaseLost) {
            // Another worker took the run over and carries on from here.
        } catch (BudgetExhausted $exception) {
            $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['error' => $exception->getMessage()], [
                'reason' => 'budget_exhausted',
                'choices' => self::DECISION_CHOICES,
            ]);
        } catch (ConstructionFailed|CannotGenerateFeature $exception) {
            $this->failRun->handle($run, $exception->getMessage(), $lease);
        }
    }

    /**
     * Move the run through planning and implementing, then hand the change to verification.
     */
    protected function advance(Run $run, RunLease $lease): void
    {
        $run->refresh();

        if ($run->status === RunStatus::Cancelling) {
            throw RunCancelled::forRun($run->id);
        }

        if ($run->status === RunStatus::Queued) {
            $this->transitionRun->handle($run, RunStatus::Planning, $lease);
        }

        if ($run->status === RunStatus::Planning) {
            $this->transitionRun->handle($run, RunStatus::Implementing, $lease);
        }

        if ($run->status !== RunStatus::Implementing) {
            return;
        }

        $workspace = $this->prepareRunWorkspace->handle($run, $lease);
        $change = $this->drivers->driver($run->driver)->build($run, new ToolSession($this->toolExecutor, $run, $lease));
        $patch = $this->extractCandidateChange->handle($workspace);

        if (trim($patch) === '') {
            $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['error' => __('The run finished without changing the project.')], [
                'reason' => 'no_changes',
                'choices' => self::DECISION_CHOICES,
            ]);

            return;
        }

        DB::transaction(function () use ($run, $lease, $change, $patch) {
            $featureRequest = $run->featureRequest;

            $featureRequest->update([
                'status' => FeatureRequestStatus::Generated,
                'solution_key' => $change->solutionKey,
                'summary' => $change->summary,
                'patch' => $patch,
                'steps' => $change->steps,
                'acceptance' => $change->acceptance,
                'error' => null,
            ]);

            $this->transitionRun->handle($run, RunStatus::Verifying, $lease, details: ['patch_sha256' => hash('sha256', $patch)]);
            $this->requestVerification->handle($featureRequest, $run);
        });
    }
}
