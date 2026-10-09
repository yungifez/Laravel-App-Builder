<?php

namespace App\Publishing\Hosts;

use App\Models\Deployment;
use App\Models\Project;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\Publishing\Contracts\PublishingHost;
use App\Publishing\Exceptions\PublishingFailed;
use App\Publishing\ReleaseProgress;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Publishes by pushing to a branch the owner chose, which their own hosting
 * deploys from.
 */
class GitBranchHost implements PublishingHost
{
    public function __construct(private ProjectRepository $repository) {}

    public function ready(Project $project): bool
    {
        return $project->deploy_remote !== null && $project->deploy_branch !== null;
    }

    public function branch(Project $project): string
    {
        return (string) $project->deploy_branch;
    }

    public function release(Project $project, Deployment $deployment): void
    {
        try {
            $this->repository->push($project, $deployment->released(), (string) $project->deploy_remote, $deployment->branch);
        } catch (RepositoryConflict $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            // The owner set this remote up, so what to check is theirs to
            // change. Git's own words go behind Details.
            throw new PublishingFailed(__('I could not send it to the repository at :address. Check the repository address and branch under "Change where to publish", then try again. Your app online has not changed.', [
                'address' => ProjectRepository::withoutCredentials((string) $project->deploy_remote, (string) $project->deploy_remote),
            ]), settings: true, previous: $exception);
        }
    }

    public function backup(Project $project, Deployment $deployment): ?string
    {
        // The owner's own hosting keeps its own backups.
        return null;
    }

    public function failure(Deployment $deployment): ?string
    {
        return null;
    }

    public function progress(Deployment $deployment): ReleaseProgress
    {
        return ReleaseProgress::Unknown;
    }

    public function errors(Deployment $deployment, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        // The owner's own hosting keeps its logs to itself.
        return null;
    }

    public function spend(): ?array
    {
        // The owner pays their own hosting.
        return null;
    }
}
