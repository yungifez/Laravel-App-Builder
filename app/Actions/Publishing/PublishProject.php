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
use App\Publishing\PublishingHostManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishProject
{
    public function __construct(
        private ProjectRepository $repository,
        private PublishingHostManager $hosts,
        private DescribeUnpublished $describeUnpublished,
    ) {}

    /**
     * Publish the project as it is now: check the current commit, then hand
     * it to the project's host. One publish runs at a time.
     *
     * "seen" is the version the owner looked at when they chose to publish.
     * When the app changed since (a change was kept in another tab), they
     * did not see what would go online, so nothing is published.
     *
     * A version that deletes or reshapes information the live app keeps
     * goes online only once the owner says yes to that version ($loseData
     * with the version they saw). Without it nothing is published, so the
     * information online stays as it is.
     *
     * @throws ValidationException when the project cannot be published now.
     */
    public function handle(Project $project, User $owner, ?string $seen = null, bool $loseData = false): Deployment
    {
        if (! $project->publishable()) {
            throw ValidationException::withMessages(['publish' => __('Choose where to publish first.')]);
        }

        if (! $this->repository->exists($project)) {
            throw ValidationException::withMessages(['publish' => __('This app has nothing to publish yet.')]);
        }

        return DB::transaction(function () use ($project, $owner, $seen, $loseData) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $active = $project->deployments()->whereIn('status', [DeploymentStatus::Checking, DeploymentStatus::Pushing])->exists();

            if ($active) {
                throw ValidationException::withMessages(['publish' => __('Your app is already being published.')]);
            }

            // Only the main app is published, never an idea.
            $head = $this->repository->head($project, Experiment::mainBranch());

            if ($seen !== null && $seen !== $head) {
                throw ValidationException::withMessages(['publish' => __('Your app changed since you looked. Check what goes online now, then put it online.')]);
            }

            $losesData = $this->losesData($project, $head);

            if ($losesData && ! ($loseData && $seen === $head)) {
                throw ValidationException::withMessages(['lose_data' => __('This version deletes or changes information your app online keeps. Say you want that, then put it online.')]);
            }

            $deployment = $project->deployments()->create([
                'user_id' => $owner->id,
                'data_loss_confirmed_at' => $losesData ? now() : null,
                'commit_sha' => $head,
                'branch' => $this->hosts->driver($project->publishingHost())->branch($project),
                'host' => $project->publishingHost(),
                'status' => DeploymentStatus::Checking,
            ]);

            $deployment->featureRequests()->attach($this->includedChanges($project, $deployment->commit_sha));

            PublishDeployment::dispatch($deployment)->afterCommit();

            return $deployment;
        });
    }

    /**
     * Whether going online would delete or reshape information the live
     * app keeps, read from the new migrations of the changes not online
     * yet. With nothing online there is no such information.
     */
    protected function losesData(Project $project, string $head): bool
    {
        $unpublished = $this->describeUnpublished->handle($project, $head, risks: true);

        return collect($unpublished['added'] ?? [])->contains(fn (array $change) => array_intersect($change['data'] ?? [], ['deletes', 'reshapes']) !== []);
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
