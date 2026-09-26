<?php

namespace App\Actions\Experiments;

use App\Actions\Runs\CancelRun;
use App\Enums\ExperimentStatus;
use App\Models\Experiment;
use App\Models\FeatureRequest;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

class DiscardExperiment
{
    public function __construct(
        private ProjectRepository $repository,
        private SwitchExperiment $switchExperiment,
        private CancelRun $cancelRun,
    ) {}

    /**
     * Throw an idea away: stop the changes still being built in it, delete
     * its branch and go back to the main app, which never had any of it.
     *
     * @throws ValidationException when the idea is not open.
     */
    public function handle(Experiment $experiment): Experiment
    {
        if ($experiment->status !== ExperimentStatus::Open) {
            throw ValidationException::withMessages(['experiment' => __('This idea is not open any more.')]);
        }

        $project = $experiment->project;

        $experiment->featureRequests()->with('latestRun')->get()
            ->map(fn (FeatureRequest $request) => $request->latestRun)
            ->filter(fn ($run) => $run !== null && ! $run->status->finished())
            ->each(fn ($run) => $this->cancelRun->handle($run));

        $experiment->update(['status' => ExperimentStatus::Discarded, 'finished_at' => now()]);

        if ($project->experiment_id === $experiment->id) {
            $this->switchExperiment->handle($project, null);
        }

        $this->repository->deleteBranch($project, $experiment->branch, Experiment::mainBranch());

        return $experiment;
    }
}
