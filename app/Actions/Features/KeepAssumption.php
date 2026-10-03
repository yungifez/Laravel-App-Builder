<?php

namespace App\Actions\Features;

use App\Actions\Context\KeepAssumptions;
use App\Actions\Context\RecordDecision;
use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use Illuminate\Validation\ValidationException;

class KeepAssumption
{
    public function __construct(private RecordDecision $recordDecision, private ProjectNotes $notes) {}

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

        $this->forgetAssumed($featureRequest, $assumption);
    }

    /**
     * Take it out of what the notes say I assumed, so the notes hold it
     * once, as decided. Until the change is kept, its notes travel with
     * it; after, they are the app's.
     */
    protected function forgetAssumed(FeatureRequest $featureRequest, string $assumption): void
    {
        if ($featureRequest->accepted_at === null) {
            $changes = $featureRequest->note_changes ?? [];

            foreach ($changes as $path => $change) {
                if ($change['after'] !== null && ($after = KeepAssumptions::without($change['after'], $assumption)) !== null) {
                    $changes[$path]['after'] = $after;
                }
            }

            $featureRequest->update(['note_changes' => $changes === [] ? null : $changes]);

            return;
        }

        if ($featureRequest->reverted_at !== null) {
            return;
        }

        $project = $featureRequest->project;
        $branch = $project->branch();
        $files = [];

        foreach ($this->notes->files($project, $branch) as $path => $contents) {
            if (($after = KeepAssumptions::without($contents, $assumption)) !== null) {
                $files[$path] = $after;
            }
        }

        if ($files !== []) {
            $this->notes->put($project, $branch, $files);
        }
    }
}
