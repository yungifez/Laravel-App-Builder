<?php

namespace App\Actions\Features;

use App\Enums\VerificationStatus;
use App\Features\PatchSummary;
use App\Features\ScreenCheck;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Verification;

class TallyKeptProof
{
    /**
     * Add up, across the changes the owner kept, the proof that stays with
     * the app: the tests those changes added, which keep checking them on
     * every later change, and the screens they touched that were opened on
     * a phone, a tablet and a computer and found to fit. Each change says
     * this in its own proof; here it adds up to what the app has gained.
     *
     * @return array{tests: int, screens: int}
     */
    public function handle(Project $project): array
    {
        $tests = 0;
        $screens = 0;

        $project->featureRequests()
            ->whereNotNull('accepted_at')
            ->whereNull('reverted_at')
            ->with(['verifications' => fn ($query) => $query->latest('id')])
            ->select(['id', 'project_id', 'patch'])
            ->lazyById()
            ->each(function (FeatureRequest $featureRequest) use (&$tests, &$screens) {
                $tests += count(PatchSummary::addedTests($featureRequest->patch));

                /** @var Verification|null $verification */
                $verification = $featureRequest->verifications->first();

                if ($verification?->status === VerificationStatus::Passed && ScreenCheck::found($verification->screens, $featureRequest->patch) === []) {
                    $screens += count(ScreenCheck::changed($verification->screens, $featureRequest->patch));
                }
            });

        return ['tests' => $tests, 'screens' => $screens];
    }
}
