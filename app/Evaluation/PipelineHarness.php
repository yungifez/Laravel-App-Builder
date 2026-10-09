<?php

namespace App\Evaluation;

use App\Actions\Context\AssessPreservation;
use App\Actions\Features\RequestFeature;
use App\Actions\Features\RequestVerification;
use App\Actions\Projects\CreateProject;
use App\Actions\Runs\GatherReviewEvidence;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Runs\ConstructionDriverManager;
use App\Runs\Plan;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs tasks through the real pipeline for the evaluation (the `sdk`
 * construction driver, local workspaces, the platform's verification and
 * review), working the queue in this process so the model hand-offs apply.
 */
class PipelineHarness
{
    public function __construct(
        protected CreateProject $createProject,
        protected RequestFeature $requestFeature,
        protected RequestVerification $requestVerification,
        protected GatherReviewEvidence $gatherReviewEvidence,
        protected AssessPreservation $assessPreservation,
        protected ConstructionDriverManager $drivers,
    ) {}

    /**
     * Point the pipeline at local workspaces whose setup copies the installed
     * dependencies instead of downloading them.
     */
    public static function configure(): void
    {
        if (Handoff::fromConfig() === null) {
            throw new RuntimeException('Set BUILDER_EVAL_HANDOFF so the pipeline\'s model calls are handed off.');
        }

        config([
            'builder.construction.driver' => 'sdk',
            'builder.construction.workspace_driver' => 'local',
            'builder.construction.setup' => Workbench::setupSteps(),
            'builder.verification.workspace_driver' => 'local',
            'builder.verification.setup' => Workbench::setupSteps(),
        ]);
    }

    /**
     * Run a request through the pipeline until it finishes or stops for the
     * owner's decision.
     */
    public function run(string $prompt, int $timeoutSeconds = 7200): Run
    {
        $owner = $this->owner();
        $featureRequest = $this->requestFeature->handle($this->project($owner), $owner, $prompt);
        $run = $featureRequest->latestRun ?? throw new RuntimeException('The request started no run.');

        $this->work(function () use ($run) {
            $status = $run->refresh()->status;

            return $status->finished() || $status === RunStatus::NeedsUserDecision;
        }, $timeoutSeconds);

        return $run;
    }

    /**
     * Verify a patch with the platform's verification, as the pipeline would,
     * in a new feature request standing in for the run's change.
     */
    public function verify(FeatureRequest $original, string $patch, int $timeoutSeconds = 3600): Verification
    {
        $copy = $original->project->featureRequests()->create([
            'user_id' => $original->user_id,
            'prompt' => $original->prompt,
            'status' => FeatureRequestStatus::Generated,
            'generator' => $original->generator,
            'summary' => $original->summary,
            'patch' => $patch,
            'steps' => $original->steps,
            'acceptance' => $original->acceptance,
        ]);

        $verification = $this->requestVerification->handle($copy);

        $this->work(fn () => $verification->refresh()->status->finished(), $timeoutSeconds);

        return $verification;
    }

    /**
     * Review a verified change the way the run's review stage does: the
     * reviewer gets the same evidence, test results, role probes and
     * security findings included, and the review is assessed for what
     * should be preserved.
     *
     * @return array{approved: bool, summary: string, findings: list<array{severity: string, summary: string, file: string|null}>, changes: list<array<string, mixed>>, classification: array<string, mixed>, preserved: list<array<string, mixed>>}
     */
    public function review(Run $run, Verification $verification): array
    {
        $plan = $run->plan !== null ? Plan::fromArray($run->plan) : throw new RuntimeException('The run has no plan.');
        $projectContext = $this->gatherReviewEvidence->projectContext($run);
        $evidence = $this->gatherReviewEvidence->handle($run, $plan, $verification);
        $classification = $evidence->classification;

        $review = $this->drivers->driver($run->driver)->review($run, $evidence)
            ->withGuardingTestsMinor($verification->evidence['new_tests'] ?? []);

        return [
            'approved' => $review->approved,
            'summary' => $review->summary,
            'findings' => $review->findings,
            'changes' => array_map(fn (array $change) => [
                ...$change,
                'area_name' => $change['area'] === null ? null : ($evidence->areaNames[$change['area']] ?? $change['area']),
                'section' => $classification->sectionFor($change['area']),
            ], $review->changes),
            'classification' => $classification->toArray(),
            'preserved' => $this->assessPreservation->handle($plan, $classification, $projectContext, $evidence->verificationResults, $review),
        ];
    }

    /**
     * Get the evaluation's owner.
     */
    protected function owner(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'evaluation@builder.test'],
            ['name' => 'Evaluation', 'password' => Hash::make(Str::random(40))],
        );
    }

    /**
     * Get the evaluation's project for the configured source.
     */
    protected function project(User $owner): Project
    {
        $source = Suite::resolve((string) config('evaluation.project'));

        return $owner->projects()->where('source_path', $source)->first()
            ?? $this->createProject->handle($owner, 'Evaluation', $source);
    }

    /**
     * Work the queue in this process until the condition holds.
     *
     * @param  Closure(): bool  $done
     *
     * @throws RuntimeException when it does not hold in time.
     */
    protected function work(Closure $done, int $timeoutSeconds): void
    {
        $deadline = now()->addSeconds($timeoutSeconds);

        while (! $done()) {
            if (now()->isAfter($deadline)) {
                throw new RuntimeException("The pipeline did not finish within {$timeoutSeconds} seconds.");
            }

            Artisan::call('queue:work', ['--once' => true, '--sleep' => 1, '--timeout' => 3700]);
        }
    }
}
