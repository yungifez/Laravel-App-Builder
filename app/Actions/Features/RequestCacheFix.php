<?php

namespace App\Actions\Features;

use App\Enums\FeatureRequestStatus;
use App\Features\ProductionCaches;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Turn what kept the app from going online before a change into an ask to
 * fix it, with one click, so the line that says so is never a dead end
 * (§27.9). The change itself is not at fault, so the fix is its own ask
 * on the app as it is. The owner's words stay plain; the builder gets
 * what the check printed.
 */
class RequestCacheFix
{
    public function __construct(private RequestFeature $requestFeature) {}

    /**
     * Ask for the fix, or get the one already asked for, so a second click
     * does not pay for the same work twice.
     *
     * @throws ValidationException when the change's latest check found no such old problem.
     */
    public function handle(FeatureRequest $featureRequest, User $requester): FeatureRequest
    {
        $verification = $featureRequest->verifications()->latest('id')->first();
        $result = collect($verification->results ?? [])->firstWhere('name', ProductionCaches::CHECK);

        if ($verification === null || ! ProductionCaches::failedBefore($result)) {
            throw ValidationException::withMessages(['fix' => __('The last check found nothing that keeps your app from going online.')]);
        }

        $asked = $featureRequest->project->featureRequests()
            ->where('failed_checks->verification_id', $verification->id)
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled])
            ->latest('id')
            ->first();

        if ($asked !== null) {
            return $asked;
        }

        return $this->requestFeature->handle(
            $featureRequest->project,
            $requester,
            __('Fix what keeps my app from going online.'),
            experiment: null,
            failedChecks: ['verification_id' => $verification->id, 'checks' => [['name' => ProductionCaches::CHECK, 'output' => (string) $result['output']]]],
        );
    }
}
