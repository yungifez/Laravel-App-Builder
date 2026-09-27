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
 * Turn the errors the published app raised into an ask to fix them, with
 * one click. The owner's words stay plain; the builder gets the errors.
 */
class RequestLiveErrorFix
{
    public function __construct(private RequestFeature $requestFeature) {}

    /**
     * Ask for a fix of the online version's errors, or get the fix already
     * asked for, so a second click does not pay for the same work twice.
     *
     * @throws ValidationException when the online version has no errors.
     */
    public function handle(Project $project, User $requester): FeatureRequest
    {
        $online = $project->deployments()->where('status', DeploymentStatus::Published)->latest('id')->first();

        if (! $online instanceof Deployment || $online->liveErrorCount() === 0) {
            throw ValidationException::withMessages(['fix' => __('Your app online has not run into problems.')]);
        }

        $asked = $project->featureRequests()
            ->where('live_errors->deployment_id', $online->id)
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled])
            ->latest('id')
            ->first();

        if ($asked !== null) {
            return $asked;
        }

        $errors = array_map(fn (array $kind) => [
            'class' => $kind['class'],
            'message' => $kind['message'],
            'count' => $kind['count'],
        ], $online->live_errors ?? []);

        // The fix is for what is online: the main app, not an open idea.
        return $this->requestFeature->handle(
            $project,
            $requester,
            __('Fix the problems people ran into in my app online.'),
            experiment: null,
            liveErrors: ['deployment_id' => $online->id, 'errors' => $errors],
        );
    }
}
