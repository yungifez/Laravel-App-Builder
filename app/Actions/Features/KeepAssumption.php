<?php

namespace App\Actions\Features;

use App\Actions\Context\RecordDecision;
use App\Models\FeatureRequest;
use Illuminate\Validation\ValidationException;

class KeepAssumption
{
    public function __construct(private RecordDecision $recordDecision) {}

    /**
     * Make something I decided for the owner their own decision, once they
     * read it and said to keep it. It is written into the notes' decisions,
     * so every later change follows it instead of deciding again, and the
     * change shows it as theirs.
     *
     * @throws ValidationException when the change did not decide it.
     */
    public function handle(FeatureRequest $featureRequest, string $assumption): void
    {
        $run = $featureRequest->latestRun;

        if ($run === null || ! in_array($assumption, $run->plan['assumptions'] ?? [], true)) {
            throw ValidationException::withMessages(['assumption' => __('This change did not decide that.')]);
        }

        if (in_array($assumption, $run->kept_assumptions ?? [], true)) {
            return;
        }

        // A change not kept yet has its own copy of the notes; a kept one
        // shares the app's.
        $branch = $featureRequest->accepted_at === null ? $featureRequest->branch() : null;

        $this->recordDecision->handle($featureRequest->project, null, $assumption, $branch);

        $run->update(['kept_assumptions' => [...($run->kept_assumptions ?? []), $assumption]]);
    }
}
