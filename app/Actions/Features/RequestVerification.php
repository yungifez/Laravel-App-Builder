<?php

namespace App\Actions\Features;

use App\Enums\FeatureRequestStatus;
use App\Enums\VerificationStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Verification;
use Illuminate\Validation\ValidationException;

class RequestVerification
{
    /**
     * Queue a verification run for the feature request's change, on behalf
     * of the construction run that made it, if any.
     *
     * @throws ValidationException when there is no generated change or a run is already in progress.
     */
    public function handle(FeatureRequest $featureRequest, ?Run $run = null): Verification
    {
        if ($featureRequest->status !== FeatureRequestStatus::Generated) {
            throw ValidationException::withMessages([
                'verification' => __('Only a generated change can be verified.'),
            ]);
        }

        $inProgress = $featureRequest->verifications()
            ->whereIn('status', [VerificationStatus::Queued, VerificationStatus::Running])
            ->exists();

        if ($inProgress) {
            throw ValidationException::withMessages([
                'verification' => __('A verification run is already in progress.'),
            ]);
        }

        $verification = $featureRequest->verifications()->create([
            'run_id' => $run?->id,
            'status' => VerificationStatus::Queued,
        ]);

        VerifyFeatureRequest::dispatch($verification)->afterCommit();

        return $verification;
    }
}
