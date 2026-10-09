<?php

namespace App\Actions\Runs;

use App\Actions\Context\ClassifyChange;
use App\Context\ContextPack;
use App\Context\ProjectContext;
use App\Features\TestChanges;
use App\Models\Run;
use App\Models\TestObservation;
use App\Models\Verification;
use App\Runs\Plan;
use App\Runs\ReviewEvidence;

/**
 * Assemble what the reviewer judges a verified change on. The run's review
 * stage and the evaluation both use it, so the evaluation measures the
 * review that ships.
 */
class GatherReviewEvidence
{
    public function __construct(protected ClassifyChange $classifyChange) {}

    /**
     * Gather the evidence for the change the verification checked. The
     * request and its notes come from the run; the patch, the results and
     * what running the app showed come from the verification.
     */
    public function handle(Run $run, Plan $plan, Verification $verification): ReviewEvidence
    {
        $featureRequest = $run->featureRequest;
        $patch = (string) $verification->featureRequest->patch;
        $pack = $run->context !== null ? ContextPack::fromArray($run->context) : null;
        $projectContext = $this->projectContext($run);
        $observation = $this->testObservation($verification);

        return new ReviewEvidence(
            request: $featureRequest->instructions(),
            plan: $plan,
            patch: $patch,
            weakenedTests: TestChanges::weakened($patch),
            verificationStatus: $verification->status->value,
            verificationResults: $verification->results ?? [],
            projectContext: $pack->text ?? '',
            classification: $this->classifyChange->handle(
                $projectContext,
                $pack->targets ?? [],
                $patch,
                array_keys($featureRequest->note_changes ?? []),
                $observation?->map(),
                mapIncludesChange: $verification->exists && $observation?->verification_id === $verification->id,
            ),
            areaNames: array_map(fn ($capability) => $capability->name, $projectContext->capabilities),
            changeEvidence: $verification->evidence ?? [],
        );
    }

    /**
     * Get the project context the run's coder was given.
     */
    public function projectContext(Run $run): ProjectContext
    {
        return $run->context !== null ? ContextPack::fromArray($run->context)->projectContext() : new ProjectContext;
    }

    /**
     * Get the observed map of the project's tests to find the change's
     * impact: the one made while this change was checked, which knows its
     * new code, or else the latest one for the project.
     */
    protected function testObservation(Verification $verification): ?TestObservation
    {
        $own = $verification->exists
            ? TestObservation::query()->where('verification_id', $verification->id)->whereNull('error')->first()
            : null;

        return $own ?? TestObservation::latestFor($verification->featureRequest->project);
    }
}
