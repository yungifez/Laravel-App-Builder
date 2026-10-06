<?php

namespace App\Actions\Runs;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Context\AssessCoverage;
use App\Actions\Context\AssessPreservation;
use App\Actions\Context\AssessVerifyItems;
use App\Actions\Context\ClassifyChange;
use App\Actions\Context\CompileContext;
use App\Actions\Context\KeepAssumptions;
use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\SelectAreas;
use App\Actions\Features\AcceptFindings;
use App\Actions\Features\ProposeFindings;
use App\Actions\Features\RequestVerification;
use App\Actions\Operations\SummarizeSpend;
use App\Actions\Previews\RequestPreview;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Context\Capability;
use App\Context\ChangeClassification;
use App\Context\ContextPack;
use App\Context\ProjectContext;
use App\Enums\Consequence;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Features\AppBoundaries;
use App\Features\AppContainment;
use App\Features\AppDrift;
use App\Features\AppFaults;
use App\Features\AppRoutes;
use App\Features\BoundaryCode;
use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\InventedColours;
use App\Features\MigrationChecks;
use App\Features\NarrowedFormats;
use App\Features\NewTests;
use App\Features\NodeInPhpTests;
use App\Features\OwnedRecords;
use App\Features\OwnFormatChecks;
use App\Features\PackagePolicy;
use App\Features\PatchSummary;
use App\Features\QueuedWork;
use App\Features\ScreenCheck;
use App\Features\TestChanges;
use App\Features\UndescribedImages;
use App\Features\UnsafeCode;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
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
use App\Runs\Exceptions\UsageLimitReached;
use App\Runs\Exceptions\WaitingForWorker;
use App\Runs\FieldFormats;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Runs\RunLease;
use App\Runs\ShapeQuestion;
use App\Runs\ToolExecutor;
use App\Runs\ToolSession;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConstructRun
{
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
        private AssessCoverage $assessCoverage,
        private AssessPreservation $assessPreservation,
        private AssessVerifyItems $assessVerifyItems,
        private FormatChange $formatChange,
        private RequestPreview $requestPreview,
        private SummarizeSpend $summarizeSpend,
        private MeasureUsage $measureUsage,
        private AcceptFindings $acceptFindings,
        private ProposeFindings $proposeFindings,
        private ReadProjectContext $readProjectContext,
        private ScaffoldDataShape $scaffoldDataShape,
        private KeepAssumptions $keepAssumptions,
        private WriteTestsFirst $writeTestsFirst,
        private ShapeQuestion $shapeQuestion,
        private FieldFormats $fieldFormats,
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
            $this->stopForDecision($run, $lease, $exception->getMessage(), StopReason::BudgetExhausted, ['feedback' => [
                'reason' => StopReason::BudgetExhausted->value,
                'details' => [...($run->feedback['details'] ?? []), __('You stopped before you finished. Finish the change.')],
            ]]);
        } catch (ProvidersUnavailable $exception) {
            $this->stopForDecision($run, $lease, $exception->getMessage(), $exception->reason());
        } catch (ConstructionFailed $exception) {
            $this->failRun->handle($run, $exception->getMessage(), StopReason::ConstructionFailed, $lease);
        } catch (CannotGenerateFeature $exception) {
            $this->failRun->handle($run, $exception->getMessage(), StopReason::CannotGenerate, $lease);
        } catch (SpendLimitReached $exception) {
            $this->failRun->handle($run, $exception->getMessage(), StopReason::SpendLimit, $lease);
        } catch (UsageLimitReached $exception) {
            $this->failRun->handle($run, $exception->getMessage(), StopReason::UsageLimit, $lease);
        }
    }

    /**
     * Take the run through its worker-owned states until it waits on
     * verification, finishes or stops.
     */
    protected function advance(Run $run, RunLease $lease): void
    {
        while (true) {
            $run->refresh();

            // Read for each step: an owner may take a change over to their
            // own tool while it is still being planned.
            $driver = $this->drivers->driver($run->driver);

            // A change already built and checked is still reviewed: that
            // is one small call, and throwing the work away costs more. A
            // repair after the review goes back through implementing.
            if (in_array($run->status, [RunStatus::Planning, RunStatus::Implementing], true)) {
                $this->ensureWithinDailySpend();
                $this->ensureWithinPlan($run);
                $this->ensureWithinRunSpend($run);
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
     * Ask the planner for the plan and, for new records, their shape. Null
     * when the run now waits for the owner or was only a question.
     */
    protected function planned(Run $run, RunLease $lease, ConstructionDriver $driver, PlanningContext $planningContext, Workspace $workspace): ?Plan
    {
        // Each AI call waits for the one before it, so the owner may have
        // cancelled in the meantime: no paid call starts after that.
        RunCancelled::throwIfCancelling($run);
        $plan = $driver->plan($run, $planningContext);
        RunCancelled::throwIfCancelling($run);

        // One product question before building (§7): the run waits for the
        // owner and plans again with their answer. The gate is the run's
        // question limit and what a wrong guess would cost, not the model's
        // wish to ask. Anything cheaper is built on the recommended option
        // and shown with the change for the owner to review.
        if ($plan->question !== null && $planningContext->mayAsk) {
            if ($plan->asksOwner(config('builder.construction.questions.ask_about'))) {
                $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['question' => $plan->question, 'error' => null], [
                    'reason' => StopReason::Question,
                    'question' => $plan->question['text'],
                ]);

                return null;
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

            return null;
        }

        // After the questions, so a run that stops for the owner or only
        // answers asks for no shape; before the shape question, which
        // asks about it.
        RunCancelled::throwIfCancelling($run);

        return $driver->shape($run, $plan, $planningContext);
    }

    /**
     * Get the plan the run last stopped to ask about the shape of, once
     * that question is answered. Null when the run stopped for anything
     * else, as an answer to the planner's own question changes the plan.
     */
    protected function planShapeAskedAbout(Run $run): ?Plan
    {
        $asked = $run->events()->whereIn('type', ['shape_asked', 'format_asked'])->reorder('sequence', 'desc')->first();
        $stopped = (int) $run->events()->where('type', 'status')->where('data->to', RunStatus::NeedsUserDecision->value)->max('sequence');

        if ($asked === null || $asked->sequence !== $stopped - 1) {
            return null;
        }

        $plan = Plan::fromArray($asked->data['plan']);

        $answered = $asked->type === 'format_asked' ? $this->fieldFormats->answered($run->answers ?? []) : $this->shapeQuestion->answered($plan, $run->answers ?? []);

        return $answered === null ? null : $plan;
    }

    /**
     * Show the owner a new record's shape that is hard to change later
     * before it is built (§8), through the same pause as a question. A
     * shape the owner answered about is built as they said. Null when the
     * run now waits for the owner.
     */
    protected function shaped(Run $run, RunLease $lease, Plan $plan, bool $mayAsk): ?Plan
    {
        $question = $this->shapeQuestion->for($plan);

        if ($question === null) {
            return $plan;
        }

        $answer = $this->shapeQuestion->answered($plan, $run->answers ?? []);

        if ($answer !== null) {
            return $this->shapeQuestion->apply($plan, $answer);
        }

        if (! $mayAsk || ! in_array(Consequence::DataShape->value, config('builder.construction.questions.ask_about'), true)) {
            return $plan;
        }

        // Kept so the answer is applied to this plan, not to a new one.
        $this->recordEvent($run, $lease, 'shape_asked', ['plan' => $plan->toArray()]);

        $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['question' => $question, 'error' => null], [
            'reason' => StopReason::Question,
            'question' => $question['text'],
        ]);

        return null;
    }

    /**
     * Settle each new field's format from the owner's answers and the
     * notes (§9 Formats). An amount whose currency nobody named is asked
     * about through the same pause as a question, when the owner can be
     * asked. Null when the run now waits for the owner.
     */
    protected function formatted(Run $run, RunLease $lease, Plan $plan, PlanningContext $planningContext): ?Plan
    {
        $areas = $planningContext->areas + array_fill_keys($planningContext->projectContext->known($plan->capabilities), SelectAreas::PLANNER);
        $settled = $this->fieldFormats->settle($plan, $planningContext->projectContext, array_map(strval(...), array_keys($areas)), $run->answers ?? []);
        $question = $this->fieldFormats->question($settled);

        if ($question === null) {
            return $settled;
        }

        if (! $planningContext->mayAsk || ! in_array(Consequence::Money->value, config('builder.construction.questions.ask_about'), true)) {
            return $this->fieldFormats->unasked($settled);
        }

        // The plan before settling is kept, so the answer settles it once.
        $this->recordEvent($run, $lease, 'format_asked', ['plan' => $plan->toArray()]);

        $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['question' => $question, 'error' => null], [
            'reason' => StopReason::Question,
            'question' => $question['text'],
        ]);

        return null;
    }

    /**
     * Prepare the workspace, have the driver plan the change, compile the
     * project context for the areas the change is about, and save both.
     */
    protected function plan(Run $run, RunLease $lease, ConstructionDriver $driver): void
    {
        $workspace = $this->prepareRunWorkspace->handle($run, $lease);
        $planningContext = $this->gatherPlanningContext->handle($run, $workspace);
        $plan = $this->plannedBefore($run, $lease) ?? $this->planAnew($run, $lease, $driver, $planningContext, $workspace);

        if ($plan === null) {
            return;
        }

        // The areas come from evidence first; the planner's guess only adds.
        $chosen = $planningContext->areas + array_fill_keys($planningContext->projectContext->known($plan->capabilities), SelectAreas::PLANNER);
        $pack = $this->compileContext->handle($planningContext->projectContext, array_map(strval(...), array_keys($chosen)), files: $planningContext->files);

        $this->recordEvent($run, $lease, 'context_compiled', [
            'mode' => $pack->mode->value,
            'targets' => $pack->targets,
            'chosen' => $chosen,
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
     * Plan the change with the paid calls: the plan, the shape of new
     * records and the tests written first. The finished plan is saved
     * with what it was made from, so a run that stops on our side before
     * it builds does not pay for it again. Null when the run now waits for
     * the owner or was only a question.
     */
    protected function planAnew(Run $run, RunLease $lease, ConstructionDriver $driver, PlanningContext $planningContext, Workspace $workspace): ?Plan
    {
        // An answer to the shape question changes only the shape, so the
        // plan it asked about is built on without planning again.
        $plan = $this->planShapeAskedAbout($run) ?? $this->planned($run, $lease, $driver, $planningContext, $workspace);

        if ($plan === null) {
            return null;
        }

        $plan = $this->shaped($run, $lease, $plan, $planningContext->mayAsk);

        if ($plan === null) {
            return null;
        }

        $plan = $this->formatted($run, $lease, $plan, $planningContext);

        if ($plan === null) {
            return null;
        }

        RunCancelled::throwIfCancelling($run);
        $plan = $this->writeTestsFirst->handle($run, $plan, $workspace, $planningContext);

        $this->recordEvent($run, $lease, 'planned', ['key' => $this->planningKey($run), 'plan' => $plan->toArray()]);

        return $plan;
    }

    /**
     * Get the plan this run already finished from the same code and the
     * same answers, when it stopped on our side (a worker restart, a lost
     * lease) before it started to build. Null when there is none: a new
     * answer from the owner plans again.
     */
    protected function plannedBefore(Run $run, RunLease $lease): ?Plan
    {
        $planned = $run->events()->where('type', 'planned')->where('data->key', $this->planningKey($run))->latest('sequence')->first();

        if ($planned === null) {
            return null;
        }

        $this->recordEvent($run, $lease, 'plan_reused', ['planned' => $planned->sequence]);

        return Plan::fromArray($planned->data['plan']);
    }

    /**
     * Get what a plan is made from that can change between two tries: the
     * code the change starts from and the owner's answers.
     */
    protected function planningKey(Run $run): string
    {
        return hash('sha256', (string) json_encode([$run->featureRequest->base_revision, $run->answers ?? [], $run->question_limit]));
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
     * Have each written test that held the change back the same way twice
     * corrected once before the next try (§12), and tell the coder. The
     * owner sees each correction in the proof; it is never silent.
     */
    protected function correctWrittenTests(Run $run, RunLease $lease, Workspace $workspace, Plan $plan): Plan
    {
        /** @var list<string> $details */
        $details = [];

        foreach ($run->feedback['tests'] ?? [] as $test) {
            if ($run->events()->whereIn('type', ['written_test_rewritten', 'written_test_not_rewritten'])->where('data->file', $test['file'])->where('data->test', $test['name'])->exists()) {
                continue;
            }

            $corrected = $this->writeTestsFirst->rewrite($run, $plan, $workspace, $test);

            if ($corrected === null) {
                $this->recordEvent($run, $lease, 'written_test_not_rewritten', ['file' => $test['file'], 'test' => $test['name'], 'reason' => $test['message']]);

                continue;
            }

            $plan = $corrected;
            $details[] = (string) __('The test ":name" in :file, written before you started, was wrong and has been corrected. Its new version is under "Tests already written": build the change so it passes. The problems below are from before it was corrected.', ['name' => $test['name'], 'file' => $test['file']]);
            $this->recordEvent($run, $lease, 'written_test_rewritten', ['file' => $test['file'], 'test' => $test['name'], 'reason' => $test['message']]);
        }

        $this->transitionRun->handle($run, RunStatus::Implementing, $lease, [
            'plan' => $plan->toArray(),
            'feedback' => ['reason' => 'verification_failed', 'details' => [...$details, ...(array) ($run->feedback['details'] ?? [])]],
        ], ['reason' => $details === [] ? 'verification_failed' : 'written_test_rewritten']);

        return $plan;
    }

    /**
     * Have the driver build (or repair) the change, read it back from the
     * workspace, and hand it to verification.
     */
    protected function implement(Run $run, RunLease $lease, ConstructionDriver $driver): void
    {
        $workspace = $this->prepareRunWorkspace->handle($run, $lease);
        $plan = $this->planFor($run);

        if (($run->feedback['reason'] ?? null) === 'written_test_wrong') {
            $plan = $this->correctWrittenTests($run, $lease, $workspace, $plan);
        }

        // A worker outside our boxes writes in its own copy of the app, so
        // the files are written only where our agents work.
        if ($run->driver !== 'worker' && ($scaffolded = $this->scaffoldDataShape->handle($workspace, $plan)) !== ['files' => [], 'notes' => []]) {
            $this->recordEvent($run, $lease, 'scaffolded', $scaffolded);
        }

        // The tests written from the plan are there before the coder
        // starts, and put back after it: the change must pass them as written.
        $this->writeTestsFirst->place($workspace, $plan);

        $account = $driver->build($run, $plan, new ToolSession($this->toolExecutor, $run, $lease));
        // A worker made its code pass the tests in its own copy, so a written
        // test it changed cannot just be put back: the change goes back.
        $changed = $run->driver === 'worker' ? $this->writeTestsFirst->changed($workspace, $plan) : [];

        if (($restored = $this->writeTestsFirst->place($workspace, $plan)) !== []) {
            $this->recordEvent($run, $lease, 'written_tests_restored', ['paths' => $restored]);
        }

        $this->recordEvent($run, $lease, 'build_finished', ['attempt' => $run->repairs, 'account' => Str::limit($account, 2000)]);

        if ($changed !== []) {
            $details = array_map(fn (array $test) => $test['name'] === null
                ? __('You changed :file, which holds tests written before the change. Hand it back exactly as written, and change the app so its tests pass.', ['file' => $test['file']])
                : __('You changed the test ":name" in :file, which was written before the change. Hand it back exactly as written, and change the app so it passes.', ['name' => $test['name'], 'file' => $test['file']]), $changed);

            if ($driver->canRepair() && $run->repairs < $run->repairLimit()) {
                $this->transitionRun->handle($run, RunStatus::Implementing, $lease, [
                    'repairs' => $run->repairs + 1,
                    'feedback' => ['reason' => 'written_tests_changed', 'details' => $details],
                ], ['reason' => 'written_tests_changed', 'tests' => $changed]);
            } else {
                $this->stopForDecision($run, $lease, __('The tool making this change changed the tests written to check it, so the change proves nothing: :tests. Ask it to try again and leave those tests as they are.', [
                    'tests' => implode(', ', array_map(fn (array $test) => $test['name'] ?? $test['file'], $changed)),
                ]), StopReason::WrittenTestsChanged);
            }

            return;
        }

        // What the agent asked to keep, instead of fixing it, goes to the owner.
        $this->proposeFindings->fromReply($run, $account);

        $formatted = $this->formatChange->handle($workspace);

        if ($formatted !== []) {
            $this->recordEvent($run, $lease, 'formatted', ['formatters' => $formatted]);
        }

        $patch = $this->extractCandidateChange->handle($workspace);

        // What the plan took for granted is kept with the notes, so the
        // next change builds on it instead of guessing again.
        $assumed = $this->keepAssumptions->handle($workspace, $plan, $run->context['targets'] ?? []);

        if ($assumed !== []) {
            $this->recordEvent($run, $lease, 'assumptions_noted', ['count' => count($assumed)]);
        }

        $noteChanges = $this->extractCandidateChange->notes($workspace);

        if (trim($patch) === '') {
            $this->stopForDecision($run, $lease, __('The run finished without changing the project.'), StopReason::NoChanges);

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
            $this->requestPreview->automatically($run->featureRequest->refresh());
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

        $review = $this->reviewOnce($run, $lease, $verification, fn () => $driver->review($run, new ReviewEvidence(
            request: $featureRequest->instructions(),
            plan: $plan,
            patch: (string) $featureRequest->patch,
            weakenedTests: TestChanges::weakened($featureRequest->patch),
            verificationStatus: $verification->status->value,
            verificationResults: $verification->results ?? [],
            projectContext: $pack->text ?? '',
            classification: $classification,
            areaNames: array_map(fn ($capability) => $capability->name, $projectContext->capabilities),
            changeEvidence: $verification->evidence ?? [],
        )));

        $verified = $this->assessVerifyItems->handle($plan, $review, (string) $featureRequest->patch, $verification->results ?? [], $verification->evidence ?? []);

        if ($driver->canRepair() && config('builder.verification.require_verify_tests')) {
            $findings = [];

            foreach ($verified as $item) {
                $finding = match ($item['evidence']) {
                    'no_test' => __('No test in the change checks: :criterion', ['criterion' => $item['case']]),
                    // What the recording saw, not what the test says: an
                    // exception case is checked by a request the app refused.
                    'no_request' => __('The test ":name" for ":case" sends your app nothing, so no refusal could be seen. Make it send the request, or run the command, that the app must refuse, and assert the refusal.', ['name' => $item['test_name'] ?? '', 'case' => $item['case']]),
                    'not_refused' => __('The app let every request of the test ":name" through, but ":case" says it must refuse. Make the app refuse it, or make the test try that case.', ['name' => $item['test_name'] ?? '', 'case' => $item['case']]),
                    'not_run_by_checks' => Capability::runBySuite((string) $item['test_file'])
                        ? __('The test ":name" for ":criterion" did not run in the test suite (:file). It is missing, skipped or named differently. Name a test that exists and runs.', [
                            'name' => $item['test_name'] ?? '',
                            'criterion' => $item['case'],
                            'file' => $item['test_file'],
                        ])
                        : __('The test for ":criterion" (:file) is not run by the test suite. Check it in a test under :paths.', [
                            'criterion' => $item['case'],
                            'file' => $item['test_file'],
                            'paths' => Capability::suiteLocation(),
                        ]),
                    default => null,
                };

                if (is_string($finding)) {
                    $findings[] = $finding;
                }
            }

            // A test for what was already true guards it, but at least one
            // new test must fail without the change, or nothing shows it works.
            $measured = $verification->evidence['new_tests'] ?? [];

            if (NewTests::ending($measured, NewTests::PASSED, $featureRequest->patch) !== [] && NewTests::ending($measured, NewTests::FAILED, $featureRequest->patch) === []) {
                $findings[] = __('Every test the change added passes without it too, so nothing shows that the change works. Add a test that fails without the change and passes with it. Tests of what was already true can stay.');
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

        // A format is decided once (§9): a check of its own on a formatted
        // field sends the change back.
        if ($driver->canRepair()) {
            $review = $review->withBlockingFindings(array_map(OwnFormatChecks::finding(...), OwnFormatChecks::found($featureRequest->patch, $plan->dataShape)));
        }

        if ($driver->canRepair() && config('builder.verification.test_scan')) {
            $review = $review->withBlockingFindings(array_map(NodeInPhpTests::finding(...), NodeInPhpTests::found($featureRequest->patch)));
        }

        // The gate (direction 33): what a test run proves the change's own
        // code did where Laravel expects nothing to change, and what a
        // caused failure proves it left behind, sends the change back by
        // itself. No model decides it, and the reviewer can add to it but
        // never take from it. What the owner said the change does on purpose
        // is left out. The agent may ask to keep a finding, but only the
        // owner's yes lets it stay: until they answer, it holds the change.
        $gate = $driver->canRepair() ? $this->gate($featureRequest, $verification) : [];
        $pending = $this->proposeFindings->pending($featureRequest);
        $asked = array_values(array_filter($gate, fn (array $finding) => in_array($finding['identity'], $pending, true)));
        $gate = $this->proposeFindings->keyed($featureRequest, array_values(array_filter($gate, fn (array $finding) => ! in_array($finding['identity'], $pending, true))));
        $review = $review->withBlockingFindings(array_column($gate, 'text'));
        $review = $review->withBlockingFindings(array_map(fn (array $finding) => __(':text You asked the owner to keep this, so leave it as it is until they answer.', ['text' => $finding['text']]), $asked));

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
            'coverage' => $this->assessCoverage->handle($plan, $classification, $projectContext, $verified, $verification->results ?? []),
        ]];

        if ($review->approved) {
            $this->transitionRun->handle($run, RunStatus::Completed, $lease, $stored);

            if ($run->workspace !== null) {
                rescue(fn () => $this->destroyWorkspace->handle($run->workspace));
            }

            return;
        }

        $details = array_map(fn (array $finding) => trim(($finding['file'] !== null ? "{$finding['file']}: " : '').$finding['summary']), $review->blockingFindings() ?: $review->findings);
        $feedback = ['reason' => 'review_findings', 'details' => $details ?: [$review->summary], 'gate' => $gate];

        // Only what the agent asked the owner to keep holds the change: the
        // owner answers, not the agent. Their answer runs this review again.
        if ($asked !== [] && count($review->blockingFindings()) === count($asked)) {
            $this->stopForDecision($run, $lease, __('I asked you about something the checks found. Read it in how we know the change works, and answer.'), StopReason::FindingProposed, [
                ...$stored,
                'feedback' => $feedback,
            ]);

            return;
        }

        if ($driver->canRepair() && $run->repairs < $run->repairLimit()) {
            $this->transitionRun->handle($run, RunStatus::Implementing, $lease, [
                'repairs' => $run->repairs + 1,
                'feedback' => $feedback,
                ...$stored,
            ], ['reason' => 'review_findings']);

            return;
        }

        // The findings are kept so the owner can ask it to keep trying.
        $this->stopForDecision($run, $lease, __('The review found problems this run cannot fix: :summary', ['summary' => $review->summary]), StopReason::ReviewFindings, [
            ...$stored,
            'feedback' => $feedback,
        ]);
    }

    /**
     * Have the model review the change once for the same evidence. A second
     * review of the same patch, checked by the same verification, with the
     * same findings accepted, is paid for again but cannot know more: a
     * job retried after a stop, or a proposal the owner turned down, reuses
     * the saved one. The free checks and the gate still run each time.
     *
     * @param  Closure(): Review  $review
     */
    protected function reviewOnce(Run $run, RunLease $lease, Verification $verification, Closure $review): Review
    {
        $accepted = $this->acceptFindings->identities($run->featureRequest);
        sort($accepted);
        $key = hash('sha256', (string) json_encode([hash('sha256', (string) $run->featureRequest->patch), $verification->id, $accepted]));

        $saved = $run->events()->where('type', 'model_review')->where('data->key', $key)->latest('sequence')->first()?->data['review'] ?? null;

        if (is_array($saved)) {
            $this->recordEvent($run, $lease, 'model_review_reused', ['verification_id' => $verification->id]);

            return new Review($saved['approved'], $saved['summary'], $saved['findings'], $saved['changes'], $saved['verify']);
        }

        $fresh = $review();

        $this->recordEvent($run, $lease, 'model_review', ['key' => $key, 'review' => [
            'approved' => $fresh->approved,
            'summary' => $fresh->summary,
            'findings' => $fresh->findings,
            'changes' => $fresh->changes,
            'verify' => $fresh->verify,
        ]]);

        return $fresh;
    }

    /**
     * Get what the gate holds against the change, each by what it is: what
     * a test run proves its code did where Laravel expects nothing to
     * change, and what a caused failure proves it left behind. In a part
     * the owner asked to be extra careful with, what was only read from
     * the code, and a call to an outside service from a new place, count
     * too (strict mode). What the owner said they want is left out.
     *
     * @return list<array{kind: string, identity: string, text: string}>
     */
    protected function gate(FeatureRequest $featureRequest, Verification $verification): array
    {
        $accepted = $this->acceptFindings->identities($featureRequest);
        $evidence = $verification->evidence ?? [];
        $boundaries = AppBoundaries::without($evidence['boundaries'] ?? null, $accepted);
        $gate = [];

        if (config('builder.verification.boundaries.send_back')) {
            foreach ($boundaries['findings'] ?? [] as $finding) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => BoundaryCode::identity($finding), 'text' => AppBoundaries::finding($finding)];
            }
        }

        // A new address that changes data with no check on who may use
        // it, or one that lost its check (§12). The owner may want it, such
        // as a contact form, and says so in the proof.
        if (config('builder.verification.routes_send_back')) {
            foreach (AppRoutes::findings($evidence['routes'] ?? null, $accepted) as $finding) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => AppRoutes::identity($finding), 'text' => AppRoutes::finding($finding)];
            }
        }

        // A migration that did not run up, down and up again, or one that
        // already existed and was edited, breaks the owner's live data when
        // published (§9). The owner may keep one, such as a data migration
        // that cannot be undone on purpose.
        $migrations = $evidence['migrations'] ?? null;

        foreach (MigrationChecks::findings($migrations, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => MigrationChecks::identity($finding), 'text' => MigrationChecks::finding($finding, $migrations)];
        }

        // New queued work that does not say how it tries again or fails
        // fails quietly on the live app (§12). The owner may keep work that
        // must run once only.
        $queued = $evidence['queued'] ?? [];

        foreach (QueuedWork::findings($queued, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => QueuedWork::identity($finding), 'text' => QueuedWork::finding($finding, $queued)];
        }

        // Records that name an owner with nothing that keeps one owner's
        // from another (§12). The owner may keep records that are public
        // on purpose.
        $owners = $evidence['owners'] ?? [];

        foreach (OwnedRecords::findings($owners, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => OwnedRecords::identity($finding), 'text' => OwnedRecords::finding($finding, $owners)];
        }

        // A format made stricter that people's saved values fail (§9). The
        // old rule is kept until the owner says to turn them away.
        foreach (NarrowedFormats::findings($evidence['narrowed'] ?? null, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => NarrowedFormats::identity($finding), 'text' => NarrowedFormats::finding($finding)];
        }

        // New packages outside the dependency policy (§12, §13). The owner
        // may keep a package they chose.
        $packages = $evidence['packages'] ?? ['changes' => [], 'problems' => []];

        foreach (PackagePolicy::findings($packages, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => PackagePolicy::identity($finding), 'text' => PackagePolicy::finding($finding, $packages)];
        }

        if (config('builder.verification.faults.send_back')) {
            foreach (AppFaults::without($evidence['faults'] ?? null, $accepted)['findings'] ?? [] as $finding) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => AppFaults::identity($finding), 'text' => AppFaults::finding($finding)];
            }
        }

        $careful = $featureRequest->project->careful_areas ?? [];

        // Work that grew far past an area's ceiling, in a careful area.
        foreach ($evidence['drift']['findings'] ?? [] as $finding) {
            $identity = AppDrift::identity($finding);

            if ($finding['far'] && in_array($finding['area'], $careful, true) && ! in_array($identity, $accepted, true)) {
                $gate[] = ['kind' => AppDrift::GREW, 'identity' => $identity, 'text' => AppDrift::finding($finding, $finding['name'])];
            }
        }

        if ($careful === [] || (($boundaries['read'] ?? []) === [] && ($evidence['containment']['findings'] ?? []) === [])) {
            return $gate;
        }

        $context = $this->readProjectContext->current($featureRequest->project);
        $names = array_map(fn (Capability $capability) => $capability->name, array_filter($context->capabilities, fn (Capability $capability) => in_array($capability->key, $careful, true)));

        foreach ($boundaries['read'] ?? [] as $finding) {
            if (array_intersect($context->claiming((string) preg_replace('/:\d+$/', '', $finding['at'])), $careful) !== []) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => BoundaryCode::identity($finding), 'text' => AppBoundaries::readFinding($finding)];
            }
        }

        foreach ($evidence['containment']['findings'] ?? [] as $finding) {
            $identity = AppContainment::identity($finding);

            if (! in_array($identity, $accepted, true) && array_intersect([...$finding['from'], ...$finding['home']], $names) !== []) {
                $gate[] = ['kind' => AppContainment::CALLED_ELSEWHERE, 'identity' => $identity, 'text' => AppContainment::finding($finding)];
            }
        }

        return $gate;
    }

    /**
     * Get a review as stored on the run, with each behaviour change placed in
     * its section by the area it belongs to, and with what backs it.
     *
     * @return array{approved: bool, summary: string, findings: list<array{severity: string, summary: string, file: string|null}>, changes: list<array{area: string|null, section: string, evidence: 'tested'|'in_change'|'not_in_change', behavior: string, before: string, now: string}>, classification: array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>, observed: array{areas: array<string, int>, tests: int, unmapped: list<string>, foundation: list<string>, by_line: list<string>}|null, notes_behind: list<string>}}
     */
    protected function storedReview(Review $review, ChangeClassification $classification): array
    {
        return [
            'approved' => $review->approved,
            'summary' => $review->summary,
            'findings' => $review->findings,
            'changes' => array_map(fn (array $change) => [...$change, 'section' => $classification->sectionFor($change['area']), 'evidence' => $classification->evidenceFor($change['area'])], $review->changes),
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
     * Stop before new planning or building once today's AI spend reached
     * the limit, so a busy day cannot drain the AI accounts unseen.
     *
     * @throws SpendLimitReached
     */
    protected function ensureWithinDailySpend(): void
    {
        if ($this->summarizeSpend->dailyLimitReached()) {
            throw new SpendLimitReached(__('This is our fault: we paused new work for today to keep our costs in check. Nothing in your app changed. Try again tomorrow.'));
        }
    }

    /**
     * Stop before new planning or building once the owner used all the AI
     * use their plan includes this month.
     *
     * @throws UsageLimitReached
     */
    protected function ensureWithinPlan(Run $run): void
    {
        $usage = $this->measureUsage->handle($run->featureRequest->project->owner);

        if ($usage['reached']) {
            throw UsageLimitReached::until($usage['resets_at']);
        }
    }

    /**
     * Stop before more planning or building once this change spent what one
     * try may spend on AI, so a change that keeps failing cannot run on
     * unseen. Counted since it started, or since the owner last asked it to
     * keep trying.
     *
     * @throws BudgetExhausted
     */
    protected function ensureWithinRunSpend(Run $run): void
    {
        $limit = (float) config('builder.construction.budgets.run_usd');

        if ($limit <= 0) {
            return;
        }

        $since = $run->budgetSince();
        $spent = $run->events()->where('type', 'model_call')
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
            ->get()
            ->sum(fn (RunEvent $call) => is_numeric($call->data['cost_usd'] ?? null) ? (float) $call->data['cost_usd'] : 0.0);

        if ($spent >= $limit) {
            throw new BudgetExhausted(__('This change used all the AI work one try may take. Your app is as it was. You can ask it to keep trying.'));
        }
    }

    /**
     * Stop the run and ask the owner how to continue.
     *
     * @param  array<string, mixed>  $attributes  Other columns to save with the stop
     */
    protected function stopForDecision(Run $run, RunLease $lease, string $reason, StopReason $cause, array $attributes = []): void
    {
        $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, $lease, ['error' => $reason, ...$attributes], ['reason' => $cause]);
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
