<?php

namespace App\Actions\Publishing;

use App\Enums\DeploymentStatus;
use App\Jobs\ConfirmDeployment;
use App\Models\Deployment;
use App\Models\Experiment;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

/**
 * Check again that the newest version sent is online, without sending it
 * again. A version sent before the owner gave the app's web address had
 * nothing to check, and a check that broke on our side said nothing about
 * the app; sending the same version again would change neither.
 */
class CheckDeployment
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Start checking at once when the owner gives the web address the
     * newest version was sent without. Giving it is asking for the check.
     * Not when newer work is waiting: putting that online checks it too.
     */
    public function whenAddressGiven(Project $project): bool
    {
        $latest = $project->deployments()->latest('id')->first();

        if (blank($project->live_url)
            || $latest?->status !== DeploymentStatus::Sent
            || ! $this->repository->exists($project)
            || $latest->commit_sha !== $this->repository->head($project, Experiment::mainBranch())) {
            return false;
        }

        $this->handle($project);

        return true;
    }

    /**
     * Start checking the newest publish at the app's address.
     *
     * @throws ValidationException when there is nothing to check.
     */
    public function handle(Project $project): Deployment
    {
        $latest = $project->deployments()->latest('id')->first();

        if (blank($project->live_url)) {
            throw ValidationException::withMessages(['check' => __('Add your app\'s web address first, so I know where to check.')]);
        }

        if (! $latest instanceof Deployment || ! self::checkable($latest)) {
            throw ValidationException::withMessages(['check' => __('There is nothing to check right now.')]);
        }

        $latest->update(['status' => DeploymentStatus::Confirming, 'error' => null, 'error_cause' => null, 'error_details' => null, 'finished_at' => null]);

        // The wait starts now: the host may have had the version for days.
        ConfirmDeployment::dispatch($latest, now());

        return $latest;
    }

    /**
     * Determine if checking again can tell the owner something: the version
     * was sent with no address to check, or our own check broke.
     */
    public static function checkable(Deployment $deployment): bool
    {
        return $deployment->status === DeploymentStatus::Sent
            || ($deployment->status === DeploymentStatus::NeedsAttention && $deployment->error_cause === 'ours');
    }
}
