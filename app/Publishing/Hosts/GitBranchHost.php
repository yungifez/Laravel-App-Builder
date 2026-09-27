<?php

namespace App\Publishing\Hosts;

use App\Models\Deployment;
use App\Models\Project;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\Publishing\Contracts\PublishingHost;
use App\Publishing\Exceptions\PublishingFailed;
use App\Publishing\ReleaseProgress;
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
            $this->repository->push($project, $deployment->commit_sha, (string) $project->deploy_remote, $deployment->branch);
        } catch (RepositoryConflict $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            // The owner set this remote up, so Git's own words help them.
            throw new PublishingFailed(ProjectRepository::withoutCredentials($exception->getMessage(), (string) $project->deploy_remote), previous: $exception);
        }
    }

    public function progress(Deployment $deployment): ReleaseProgress
    {
        return ReleaseProgress::Unknown;
    }
}
