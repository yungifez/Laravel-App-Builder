<?php

namespace App\Actions\Publishing;

use App\Actions\Features\RequestFeature;
use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Turn the checks that kept the app from going online into an ask to fix
 * them, with one click. The owner's words stay plain; the builder gets
 * what each check said.
 */
class RequestCheckFix
{
    public function __construct(private RequestFeature $requestFeature) {}

    /**
     * Ask for a fix of the latest publish's failed checks, or get the fix
     * already asked for, so a second click does not pay for the same work
     * twice.
     *
     * @throws ValidationException when the latest publish did not fail a check.
     */
    public function handle(Project $project, User $requester): FeatureRequest
    {
        $latest = $project->deployments()->latest('id')->first();
        $failed = $latest instanceof Deployment && $latest->status === DeploymentStatus::Failed
            ? array_values(array_filter($latest->checks ?? [], fn (array $check) => ! $check['passed']))
            : [];

        if ($failed === []) {
            throw ValidationException::withMessages(['fix' => __('No check stopped your app going online.')]);
        }

        $asked = $project->featureRequests()
            ->where('failed_checks->deployment_id', $latest->id)
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled])
            ->latest('id')
            ->first();

        if ($asked !== null) {
            return $asked;
        }

        // The fix is for what goes online: the main app, not an open idea.
        return $this->requestFeature->handle(
            $project,
            $requester,
            __('Fix what stopped my app going online.'),
            experiment: null,
            failedChecks: [
                'deployment_id' => $latest->id,
                'checks' => array_map(fn (array $check) => ['name' => $check['name'], 'output' => $check['output'] ?? ''], $failed),
            ],
        );
    }
}
