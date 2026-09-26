<?php

namespace App\Actions\Experiments;

use App\Models\Experiment;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;

class StartExperiment
{
    public function __construct(private ProjectRepository $repository, private ShowLatestVersion $showLatestVersion) {}

    /**
     * Start trying an idea: a branch from the main app as it is now, which
     * the owner then works in. The main app does not change until they use
     * the idea.
     */
    public function handle(Project $project, User $owner, string $name): Experiment
    {
        $this->repository->import($project);
        $base = $this->repository->head($project, Experiment::mainBranch());

        return DB::transaction(function () use ($project, $owner, $name, $base) {
            $experiment = $project->experiments()->create([
                'user_id' => $owner->id,
                'name' => $name,
                'branch' => 'pending',
                'base_sha' => $base,
            ]);

            // Named by number only: the branch says nothing about the idea
            // or about how it was made.
            $experiment->update(['branch' => "ideas/{$experiment->id}"]);
            $this->repository->createBranch($project, $experiment->branch, $base);

            $project->update(['experiment_id' => $experiment->id]);
            $this->showLatestVersion->handle($project->setRelation('experiment', $experiment));

            return $experiment;
        });
    }
}
