<?php

namespace App\Actions\Runs;

use App\Actions\Context\AssessPreservation;
use App\Actions\Context\AssessVerifyItems;
use App\Actions\Context\ClassifyChange;
use App\Actions\Context\CompileContext;
use App\Actions\Features\RequestVerification;
use App\Actions\Operations\SummarizeSpend;
use App\Actions\Previews\RequestPreview;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Context\Capability;
use App\Context\ChangeClassification;
use App\Context\ContextPack;
use App\Context\ProjectContext;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\InventedColours;
use App\Features\PatchSummary;
use App\Features\ScreenCheck;
use App\Features\TestChanges;
use App\Features\UndescribedImages;
use App\Features\UnsafeCode;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\TestObservation;
use App\Models\Verification;
use App\Models\Workspace;
use App\Runs\ConstructionDriverManager;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\ProvidersUnavailable;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\Exceptions\SpendLimitReached;
use App\Runs\Exceptions\WaitingForWorker;
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
        private AssessVerifyItems $assessVerifyItems,
        private FormatChange $formatChange,
        private RequestPreview $requestPreview,
        private SummarizeSpend $summarizeSpend,
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
        } catch (WaitingForWorker) {
            // The change is written outside; handing it back runs this again.
        } catch (BudgetExhausted $exception) {
            // What is left to do is kept so the owner can ask it to keep
            // trying: whatever it was fixing, and finishing the change.
            $this->stopForDecision($run, $lease, $exception->getMessage(), 'budget_exhausted', ['feedback' => [
                'reason' => 'budget_exhausted',
                'details' => [...($run->feedback['details'] ?? []), __('You stopped before you finished. Finish the change.')],
            ]]);
        } catch (ProvidersUnavailable $exception) {
            $this->stopForDecision($run, $lease, $exception->getMessage(), 'providers_unavailable');
        } catch (ConstructionFailed $exception) {
            $this->failRun->handle($run, $exception->getMessage(), $lease, 'construction_failed');
        } catch (CannotGenerateFeature $exception) {
            $this->failRun->handle($run, $exception->getMessage(), $lease, 'cannot_generate');
        } catch (SpendLimitReached $exception) {
            $this->failRun->handle($run, $exception->getMessage(), $lease, 'spend_limit');
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

            if (in_array($run->status, [RunStatus::Planning, RunStatus::Implementing, RunStatus::Reviewing], true)) {
                $this->ensureWithinDailySpend();
            }

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
                    break;

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

        // One product question before building (§7): the run waits for the
        // owner and plans again with their answer. The gate is the run's
        // question limit and what a wrong guess would cost, not the model's
        // wish to ask. Anything cheaper is built on the recommended option
        // and shown with the change for the owner to review.
        if ($plan->question !== null && $planningContext->mayAsk) {
            if ($plan->asksOwner(config('builder.construction.questions.ask_about'))) {
                $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['question' => $plan->question, 'error' => null], [
                    'reason' => 'question',
                    'question' => $plan->question['text'],
                ]);

                return;
            }

            $this->recordEvent($run, $lease, 'question_decided', [
                'question' => $plan->question['text'],
                'option' => $plan->question['recommended'],
                'touches' => $plan->question['touches'] ?? [],
            ]);

            $plan = $plan->decidedOnRecommendation();
        }

        if ($plan->answer !== null) {
            $this->answer($run, $lease, $plan, $workspace);

            return;
        }

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
     * The owner only asked about the app. Reply from the plan and stop,
     * rather than spend minutes building, checking and reviewing nothing.
     */
    protected function answer(Run $run, RunLease $lease, Plan $plan, Workspace $workspace): void
    {
        DB::transaction(function () use ($run, $lease, $plan) {
            $run->featureRequest->update([
                'status' => FeatureRequestStatus::Answered,
                'summary' => $plan->summary,
                'error' => null,
            ]);

            $this->transitionRun->handle($run, RunStatus::Completed, $lease, ['plan' => $plan->toArray()], ['reason' => 'answered']);
        });

        rescue(fn () => $this->destroyWorkspace->handle($workspace));
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

        $formatted = $this->formatChange->handle($workspace);

        if ($formatted !== []) {
            $this->recordEvent($run, $lease, 'formatted', ['formatters' => $formatted]);
        }

        $patch = $this->extractCandidateChange->handle($workspace);
        $noteChanges = $this->extractCandidateChange->notes($workspace);

        if (trim($patch) === '') {
            $this->stopForDecision($run, $lease, __('The run finished without changing the project.'), 'no_changes');

            return;
        }

        // Tests the checks never run cannot count as evidence, and the review
        // would send the change back for them anyway. Saying so now saves a
        // verification and a review.
        $skipped = $this->testsTheChecksSkip($patch);

        if ($skipped !== [] && $driver->canRepair() && $run->repairs < $run->repairLimit()) {
            $paths = Capability::suiteLocation();

            $this->transitionRun->handle($run, RunStatus::Implementing, $lease, [
                'repairs' => $run->repairs + 1,
                'feedback' => ['reason' => 'tests_not_run', 'details' => array_map(
                    fn (string $file) => __('The checks do not run :file, so it proves nothing. Check the same behaviour in a test under :paths.', ['file' => $file, 'paths' => $paths]),
                    $skipped,
                )],
            ], ['reason' => 'tests_not_run', 'files' => $skipped]);

            return;
        }

        DB::transaction(function () use ($run, $lease, $plan, $patch, $noteChanges) {
            $featureRequest = $run->featureRequest;

            $featureRequest->update([
                'status' => FeatureRequestStatus::Generated,
                'solution_key' => $plan->solutionKey,
                'summary' => $plan->summary,
                'patch' => $patch,
                'note_changes' => $noteChanges === [] ? null : $noteChanges,
                'steps' => $plan->steps,
                'acceptance' => $plan->acceptance,
                'error' => null,
            ]);

            $this->transitionRun->handle($run, RunStatus::Verifying, $lease, ['feedback' => null], [
                'patch_sha256' => hash('sha256', $patch),
            ]);
            $this->requestVerification->handle($featureRequest, $run);
        });

        // The owner can try the change while it is checked and reviewed;
        // keeping it still waits for both.
        if (config('builder.preview.automatic')) {
            $this->requestPreview->handle($run->featureRequest->refresh());
        }
    }

    /**
     * Get the observed map of the project's tests to find the change's
     * impact: the one made while this change was checked, which knows its
     * new code, or else the latest one for the project.
     */
    protected function testObservation(FeatureRequest $featureRequest, Verification $verification): ?TestObservation
    {
        return TestObservation::query()->where('verification_id', $verification->id)->whereNull('error')->first()
            ?? TestObservation::latestFor($featureRequest->project);
    }

    /**
     * Have the driver review the verified change from the platform's evidence,
     * check that a test in the change covers each verify item, then complete
     * the run, send it back for a repair, or stop for a decision.
     */
    protected function review(Run $run, RunLease $lease, ConstructionDriver $driver): void
    {
        $featureRequest = $run->featureRequest;
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $plan = $this->planFor($run);
        $pack = $run->context !== null ? ContextPack::fromArray($run->context) : null;
        $projectContext = $pack?->projectContext() ?? new ProjectContext;
        $observation = $this->testObservation($featureRequest, $verification);
        $classification = $this->classifyChange->handle(
            $projectContext,
            $pack->targets ?? [],
            $featureRequest->patch,
            array_keys($featureRequest->note_changes ?? []),
            $observation?->map(),
            mapIncludesChange: $observation?->verification_id === $verification->id,
        );

        $review = $driver->review($run, new ReviewEvidence(
            request: $featureRequest->instructions(),
            plan: $plan,
            patch: (string) $featureRequest->patch,
            weakenedTests: TestChanges::weakened($featureRequest->patch),
            verificationStatus: $verification->status->value,
            verificationResults: $verification->results ?? [],
            projectContext: $pack->text ?? '',
            classification: $classification,
            areaNames: array_map(fn ($capability) => $capability->name, $projectContext->capabilities),
        ));

        $verified = $this->assessVerifyItems->handle($plan, $review, (string) $featureRequest->patch, $verification->results ?? []);

        if ($driver->canRepair() && config('builder.verification.require_verify_tests')) {
            $findings = [];

            foreach ($verified as $item) {
                $finding = match ($item['evidence']) {
                    'no_test' => __('No test in the change checks: :criterion', ['criterion' => $item['criterion']]),
                    'not_run_by_checks' => Capability::runBySuite((string) $item['test_file'])
                        ? __('The test ":name" for ":criterion" did not run in the test suite (:file). It is missing, skipped or named differently. Name a test that exists and runs.', [
                            'name' => $item['test_name'] ?? '',
                            'criterion' => $item['criterion'],
                            'file' => $item['test_file'],
                        ])
                        : __('The test for ":criterion" (:file) is not run by the test suite. Check it in a test under :paths.', [
                            'criterion' => $item['criterion'],
                            'file' => $item['test_file'],
                            'paths' => Capability::suiteLocation(),
                        ]),
                    default => null,
                };

                if (is_string($finding)) {
                    $findings[] = $finding;
                }
            }

            $review = $review->withBlockingFindings($findings);
        }

        if ($driver->canRepair() && config('builder.verification.safety_scan')) {
            $review = $review->withBlockingFindings(array_map(UnsafeCode::finding(...), UnsafeCode::found($featureRequest->patch)));
        }

        if ($driver->canRepair() && config('builder.verification.design_scan')) {
            $review = $review->withBlockingFindings(array_map(InventedColours::finding(...), InventedColours::found($featureRequest->patch)));
            $review = $review->withBlockingFindings(array_map(UndescribedImages::finding(...), UndescribedImages::found($featureRequest->patch)));
        }

        if ($driver->canRepair() && config('builder.verification.screens.enabled')) {
            $review = $review->withBlockingFindings(array_map(ScreenCheck::finding(...), ScreenCheck::found($verification->screens, $featureRequest->patch)));
        }

        $this->recordEvent($run, $lease, 'review', [
            'approved' => $review->approved,
            'summary' => $review->summary,
            'findings' => $review->findings,
            'verification_id' => $verification->id,
            'verify' => array_count_values(array_column($verified, 'evidence')),
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
            'verified' => $verified,
        ]];

        if ($review->approved) {
            $this->transitionRun->handle($run, RunStatus::Completed, $lease, $stored);

            if ($run->workspace !== null) {
                rescue(fn () => $this->destroyWorkspace->handle($run->workspace));
            }

            return;
        }

        $details = array_map(fn (array $finding) => trim(($finding['file'] !== null ? "{$finding['file']}: " : '').$finding['summary']), $review->blockingFindings() ?: $review->findings);

        if ($driver->canRepair() && $run->repairs < $run->repairLimit()) {
            $this->transitionRun->handle($run, RunStatus::Implementing, $lease, [
                'repairs' => $run->repairs + 1,
                'feedback' => ['reason' => 'review_findings', 'details' => $details ?: [$review->summary]],
                ...$stored,
            ], ['reason' => 'review_findings']);

            return;
        }

        // The findings are kept so the owner can ask it to keep trying.
        $this->stopForDecision($run, $lease, __('The review found problems this run cannot fix: :summary', ['summary' => $review->summary]), 'review_findings', [
            ...$stored,
            'feedback' => ['reason' => 'review_findings', 'details' => $details ?: [$review->summary]],
        ]);
    }

    /**
     * Get a review as stored on the run, with each behaviour change placed in
     * its section by the area it belongs to.
     *
     * @return array{approved: bool, summary: string, findings: list<array{severity: string, summary: string, file: string|null}>, changes: list<array{area: string|null, section: string, behavior: string, before: string, now: string}>, classification: array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>, observed?: array{areas: array<string, int>, tests: int, unmapped: list<string>, foundation?: list<string>, by_line?: list<string>}|null}}
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
     * Stop before the next model call once today's AI spend reached the
     * limit, so a busy day cannot drain the AI accounts unseen.
     *
     * @throws SpendLimitReached
     */
    protected function ensureWithinDailySpend(): void
    {
        $limit = (float) config('builder.construction.budgets.daily_usd');

        if ($limit > 0 && $this->summarizeSpend->handle(now()->startOfDay()->toImmutable())['total_usd'] >= $limit) {
            throw new SpendLimitReached(__('This is our fault: we paused new work for today to keep our costs in check. Nothing in your app changed. Try again tomorrow.'));
        }
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
     * Get the test files the change adds to or changes that the checks do not
     * run, such as a Vitest file when only tests/ is run, when the change has
     * no test that the checks do run.
     *
     * @return list<string>
     */
    protected function testsTheChecksSkip(string $patch): array
    {
        if (! config('builder.verification.require_verify_tests')) {
            return [];
        }

        $tests = array_values(array_filter(
            array_column(array_filter(PatchSummary::files($patch), fn (array $file) => $file['additions'] > 0), 'path'),
            fn (string $path) => preg_match('#(\.(test|spec)\.[cm]?[jt]sx?$)|(Test\.php$)|((^|/)(tests?|__tests__)/)#', $path) === 1,
        ));
        $skipped = array_values(array_filter($tests, fn (string $path) => ! Capability::runBySuite($path)));

        return count($skipped) === count($tests) ? $skipped : [];
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
