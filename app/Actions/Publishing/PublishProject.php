<?php

namespace App\Actions\Publishing;

use App\Enums\DeploymentStatus;
use App\Jobs\PublishDeployment;
use App\Models\Deployment;
use App\Models\Experiment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishProject
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Publish the project as it is now: check the current commit, then push
     * it. One publish runs at a time.
     *
     * @throws ValidationException when the project cannot be published now.
     */
    public function handle(Project $project, User $owner): Deployment
    {
        if (! $project->publishable()) {
            throw ValidationException::withMessages(['publish' => __('Choose where to publish first.')]);
        }

        if (! $this->repository->exists($project)) {
            throw ValidationException::withMessages(['publish' => __('This app has nothing to publish yet.')]);
        }

        return DB::transaction(function () use ($project, $owner) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $active = $project->deployments()->whereIn('status', [DeploymentStatus::Checking, DeploymentStatus::Pushing])->exists();

            if ($active) {
                throw ValidationException::withMessages(['publish' => __('Your app is already being published.')]);
            }

            $deployment = $project->deployments()->create([
                'user_id' => $owner->id,
                // Only the main app is published, never an idea.
                'commit_sha' => $this->repository->head($project, Experiment::mainBranch()),
                'branch' => (string) $project->deploy_branch,
                'status' => DeploymentStatus::Checking,
            ]);

            $deployment->featureRequests()->attach($this->includedChanges($project, $deployment->commit_sha));

            PublishDeployment::dispatch($deployment)->afterCommit();

            return $deployment;
        });
    }

    /**
     * Get the kept changes the published commit contains, read from the
     * project's history: those whose commit is in it and whose undo is not.
     *
     * @return list<int>
     */
    protected function includedChanges(Project $project, string $commit): array
    {
        $history = array_flip($this->repository->history($project, $commit));

        return array_values($project->featureRequests()
            ->whereNotNull('commit_sha')
            ->get(['id', 'commit_sha', 'revert_sha'])
            ->filter(fn (FeatureRequest $change) => isset($history[$change->commit_sha]) && ($change->revert_sha === null || ! isset($history[$change->revert_sha])))
            ->modelKeys());
    }
}
