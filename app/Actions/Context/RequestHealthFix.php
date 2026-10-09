<?php

namespace App\Actions\Context;

use App\Actions\Features\RequestFeature;
use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Turn what the full check of the app found into an ask to fix it, with
 * one click, so a finding is never a dead end. The owner's words stay
 * plain; the builder gets what each step said.
 */
class RequestHealthFix
{
    public function __construct(private RequestFeature $requestFeature, private ReadAppHealth $readAppHealth) {}

    /**
     * Ask for a fix of what the current version's checks found, the owner's
     * and the scheduler's together, or get the fix already asked for, so a
     * second click does not pay for the same work twice.
     *
     * @throws ValidationException when the latest check of the current version found nothing to fix.
     */
    public function handle(Project $project, User $requester): FeatureRequest
    {
        // A check of an earlier version may be about code that changed since.
        $health = $this->readAppHealth->handle($project);
        $failures = $health === null ? [] : $health['check']->failures();

        if ($health === null || $failures === []) {
            throw ValidationException::withMessages(['fix' => __('The last check found nothing to fix. Check your app again to see where it stands.')]);
        }

        $asked = $project->featureRequests()
            ->where('failed_checks->health_check_id', $health['id'])
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled])
            ->latest('id')
            ->first();

        if ($asked !== null) {
            return $asked;
        }

        // The fix is for the app itself, not an open idea.
        return $this->requestFeature->handle(
            $project,
            $requester,
            __('Fix what the check of my app found.'),
            experiment: null,
            failedChecks: ['health_check_id' => $health['id'], 'checks' => $failures],
        );
    }
}
