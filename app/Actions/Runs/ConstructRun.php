<?php

namespace App\Actions\Runs;

use App\Actions\Context\AssessPreservation;
use App\Actions\Context\ClassifyChange;
use App\Actions\Context\CompileContext;
use App\Actions\Features\RequestVerification;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Context\ChangeClassification;
use App\Context\ContextPack;
use App\Context\ProjectContext;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\TestChanges;
use App\Models\Run;
use App\Runs\ConstructionDriverManager;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\Plan;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Runs\RunLease;
use App\Runs\ToolExecutor;
use App\Runs\ToolSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        private GatherPlanningContext $gatherPlanningContext,
        private ConstructionDriverManager $drivers,
        private ToolExecutor $toolExecutor,
        private ExtractCandidateChange $extractCandidateChange,
        private RequestVerification $requestVerification,
        private CancelRun $cancelRun,
        private FailRun $failRun,
        private DestroyWorkspace $destroyWorkspace,
        private CompileContext $compileContext,
        private ClassifyChange $classifyChange,
        private AssessPreservation $assessPreservation,
    ) {}

    /**
     * Carry the run forward from wherever it is, as the lease holder: plan,
     * build and hand over to verification, or review a verified change.
     *
     * A resumed run picks up from its recorded state; tool calls with fixed
     * operation keys replay from the journal, so nothing is done twice.
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
            $this->stopForDecision($run, $lease, $exception->getMessage(), 'budget_exhausted');
        } catch (ConstructionFailed|CannotGenerateFeature $exception) {
            $this->failRun->handle($run, $exception->getMessage(), $lease);
        }
    }

    /**
     * Take the run through its worker-owned states until it waits on
     * verification, finishes or stops.
     */
    protected function advance(Run $run, RunLease $lease): void
    {
        $driver = $this->drivers->driver($run->driver);

        while (true) {
            $run->refresh();

            switch ($run->status) {
                case RunStatus::Cancelling:
                    throw RunCancelled::forRun($run->id);
                case RunStatus::Queued:
                    $this->transitionRun->handle($run, RunStatus::Planning, $lease);
                    break;

                case RunStatus::Planning:
                    $this->plan($run, $lease, $driver);
                    break;

                case RunStatus::Implementing:
                    $this->implement($run, $lease, $driver);

                    return;

                case RunStatus::Reviewing:
                    $this->review($run, $lease, $driver);
                    break;

                default:
                    return;
            }
        }
    }

    /**
     * Prepare the workspace, have the driver plan the change, compile the
     * project context for the areas the change is about, and save both.
     */
    protected function plan(Run $run, RunLease $lease, ConstructionDriver $driver): void
    {
        $workspace = $this->prepareRunWorkspace->handle($run, $lease);
        $planningContext = $this->gatherPlanningContext->handle($run, $workspace);
        $plan = $driver->plan($run, $planningContext);
        $pack = $this->compileContext->handle($planningContext->projectContext, [...$planningContext->preselectedCapabilities(), ...$plan->capabilities]);

        $this->recordEvent($run, $lease, 'context_compiled', [
            'mode' => $pack->mode->value,
            'targets' => $pack->targets,
            'included' => $pack->included,
            'tokens' => $pack->tokens(),
            'problems' => $pack->problems,
        ]);

        $this->transitionRun->handle($run, RunStatus::Implementing, $lease, ['plan' => $plan->toArray(), 'context' => $pack->toArray()], [
            'summary' => $plan->summary,
            'acceptance_criteria' => count($plan->acceptanceCriteria),
            'protected_suites' => count($plan->acceptance),
        ]);
    }

    /**
     * Have the driver build (or repair) the change, read it back from the
     * workspace, and hand it to verification.
     */
    protected function implement(Run $run, RunLease $lease, ConstructionDriver $driver): void
    {
        $workspace = $this->prepareRunWorkspace->handle($run, $lease);
        $plan = $this->planFor($run);
        $account = $driver->build($run, $plan, new ToolSession($this->toolExecutor, $run, $lease));

        $this->recordEvent($run, $lease, 'build_finished', ['attempt' => $run->repairs, 'account' => Str::limit($account, 2000)]);

        $patch = $this->extractCandidateChange->handle($workspace);

        if (trim($patch) === '') {
            $this->stopForDecision($run, $lease, __('The run finished without changing the project.'), 'no_changes');

            return;
        }

        DB::transaction(function () use ($run, $lease, $plan, $patch) {
            $featureRequest = $run->featureRequest;

            $featureRequest->update([
                'status' => FeatureRequestStatus::Generated,
                'solution_key' => $plan->solutionKey,
                'summary' => $plan->summary,
                'patch' => $patch,
                'steps' => $plan->steps,
                'acceptance' => $plan->acceptance,
                'error' => null,
            ]);

            $this->transitionRun->handle($run, RunStatus::Verifying, $lease, ['feedback' => null], [
                'patch_sha256' => hash('sha256', $patch),
            ]);
            $this->requestVerification->handle($featureRequest, $run);
        });
    }

    /**
     * Have the driver review the verified change from the platform's evidence,
     * then complete the run, send it back for a repair, or stop for a decision.
     */
    protected function review(Run $run, RunLease $lease, ConstructionDriver $driver): void
    {
        $featureRequest = $run->featureRequest;
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $plan = $this->planFor($run);
        $pack = $run->context !== null ? ContextPack::fromArray($run->context) : null;
        $projectContext = $pack?->projectContext() ?? new ProjectContext;
        $classification = $this->classifyChange->handle($projectContext, $pack->targets ?? [], $featureRequest->patch);

        $review = $driver->review($run, new ReviewEvidence(
            request: $featureRequest->prompt,
            plan: $plan,
            patch: (string) $featureRequest->patch,
            weakenedTests: TestChanges::weakened($featureRequest->patch),
            verificationStatus: $verification->status->value,
            verificationResults: $verification->results ?? [],
            projectContext: $pack->text ?? '',
            classification: $classification,
            areaNames: array_map(fn ($capability) => $capability->name, $projectContext->capabilities),
        ));

        $this->recordEvent($run, $lease, 'review', [
            'approved' => $review->approved,
            'summary' => $review->summary,
            'findings' => $review->findings,
            'verification_id' => $verification->id,
            'areas' => [
                'requested' => array_keys($classification->requested),
                'may_also_affect' => array_keys($classification->mayAlsoAffect),
                'unexpected' => array_keys($classification->unexpected),
                'unclaimed_files' => count($classification->unclaimed),
            ],
        ]);

        $stored = ['review' => [
            ...$this->storedReview($review, $classification),
            'preserved' => $this->assessPreservation->handle($plan, $classification, $projectContext, $verification->results ?? []),
        ]];

        if ($review->approved) {
            $this->transitionRun->handle($run, RunStatus::Completed, $lease, $stored);

            if ($run->workspace !== null) {
                rescue(fn () => $this->destroyWorkspace->handle($run->workspace));
            }

            return;
        }

        $details = array_map(fn (array $finding) => trim(($finding['file'] !== null ? "{$finding['file']}: " : '').$finding['summary']), $review->blockingFindings() ?: $review->findings);

        if ($driver->canRepair() && $run->repairs < (int) config('builder.construction.budgets.repairs')) {
            $this->transitionRun->handle($run, RunStatus::Implementing, $lease, [
                'repairs' => $run->repairs + 1,
                'feedback' => ['reason' => 'review_findings', 'details' => $details ?: [$review->summary]],
                ...$stored,
            ], ['reason' => 'review_findings']);

            return;
        }

        $this->stopForDecision($run, $lease, __('The review found problems this run cannot fix: :summary', ['summary' => $review->summary]), 'review_findings', $stored);
    }

    /**
     * Get a review as stored on the run, with each behaviour change placed in
     * its section by the area it belongs to.
     *
     * @return array{approved: bool, summary: string, findings: list<array{severity: string, summary: string, file: string|null}>, changes: list<array{area: string|null, section: string, behavior: string, before: string, now: string}>, classification: array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>}}
     */
    protected function storedReview(Review $review, ChangeClassification $classification): array
    {
        return [
            'approved' => $review->approved,
            'summary' => $review->summary,
            'findings' => $review->findings,
            'changes' => array_map(fn (array $change) => [...$change, 'section' => $classification->sectionFor($change['area'])], $review->changes),
            'classification' => $classification->toArray(),
        ];
    }

    /**
     * Get the run's saved plan, or one describing the request's existing change.
     */
    protected function planFor(Run $run): Plan
    {
        if ($run->plan !== null) {
            return Plan::fromArray($run->plan);
        }

        $featureRequest = $run->featureRequest;

        return new Plan(
            summary: (string) $featureRequest->summary,
            steps: $featureRequest->steps ?? [],
            acceptance: $featureRequest->acceptance ?? [],
            solutionKey: $featureRequest->solution_key,
        );
    }

    /**
     * Stop the run and ask the owner how to continue.
     *
     * @param  array<string, mixed>  $attributes  Other columns to save with the stop
     */
    protected function stopForDecision(Run $run, RunLease $lease, string $reason, string $cause, array $attributes = []): void
    {
        $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['error' => $reason, ...$attributes], [
            'reason' => $cause,
            'choices' => self::DECISION_CHOICES,
        ]);
    }

    /**
     * Log an event while the lease still holds the run.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LeaseLost
     */
    protected function recordEvent(Run $run, RunLease $lease, string $type, array $data): void
    {
        DB::transaction(function () use ($run, $lease, $type, $data) {
            $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

            $lease->assertHeldOn($locked);

            $locked->recordEvent($type, $data);
        });
    }
}
