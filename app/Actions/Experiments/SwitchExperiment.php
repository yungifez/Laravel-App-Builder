<?php

namespace App\Actions\Experiments;

use App\Enums\ExperimentStatus;
use App\Models\Experiment;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class SwitchExperiment
{
    public function __construct(private ShowLatestVersion $showLatestVersion) {}

    /**
     * Work in another open idea, or in the main app (null). Nothing is
     * lost: each keeps its own changes.
     *
     * @throws ValidationException when the idea is not an open idea of the project.
     */
    public function handle(Project $project, ?Experiment $experiment): void
    {
        if ($experiment !== null && ($experiment->project_id !== $project->id || $experiment->status !== ExperimentStatus::Open)) {
            throw ValidationException::withMessages(['experiment' => __('This idea is not open any more.')]);
        }

        if ($project->experiment_id === $experiment?->id) {
            return;
        }

        $project->update(['experiment_id' => $experiment?->id]);
        $this->showLatestVersion->handle($project->setRelation('experiment', $experiment));
    }
}
