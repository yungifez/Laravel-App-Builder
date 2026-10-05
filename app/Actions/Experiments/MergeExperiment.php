<?php

namespace App\Actions\Experiments;

use App\Context\ProjectNotes;
use App\Enums\ExperimentStatus;
use App\Models\Experiment;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

class MergeExperiment
{
    public function __construct(private ProjectRepository $repository, private ProjectNotes $notes, private SwitchExperiment $switchExperiment) {}

    /**
     * Use an idea in the app: bring its branch into the main branch as one
     * commit, then go back to the main app. When the main app changed the
     * same places since the idea started, nothing is merged and the owner
     * is told.
     *
     * @throws ValidationException when the idea is not open or does not merge cleanly.
     */
    public function handle(Experiment $experiment, User $owner): Experiment
    {
        if ($experiment->status !== ExperimentStatus::Open) {
            throw ValidationException::withMessages(['experiment' => __('This idea is not open any more.')]);
        }

        $project = $experiment->project;

        try {
            $sha = $this->repository->merge(
                $project,
                $experiment->branch,
                Experiment::mainBranch(),
                // The idea's name is the owner's, so it stays out of the app's history.
                'Combine several changes',
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['experiment' => $exception->getMessage().' '.__('Ask for the change again in your app instead.')]);
        }

        $experiment->update(['status' => ExperimentStatus::Merged, 'merge_sha' => $sha, 'finished_at' => now()]);
        $this->notes->merge($project, $experiment->branch, Experiment::mainBranch());
        $this->repository->deleteBranch($project, $experiment->branch, Experiment::mainBranch());

        if ($project->experiment_id === $experiment->id) {
            $this->switchExperiment->handle($project, null);
        }

        return $experiment;
    }
}
