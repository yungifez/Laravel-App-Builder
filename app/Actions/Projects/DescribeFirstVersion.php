<?php

namespace App\Actions\Projects;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Features\RetryFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;

/**
 * Where the first version of an app started here stands, while the app has
 * none kept. Until then the app is only the template, so the workspace
 * shows this in place of the template's welcome page.
 */
class DescribeFirstVersion
{
    public function __construct(protected DescribeFeatureRequest $describeFeatureRequest) {}

    /**
     * @return array{change: string, state: 'making'|'asking'|'ready'|'stopped', error: string|null, can_retry: bool}|null
     */
    public function handle(Project $project): ?array
    {
        // An app brought in, or one with a kept change, is the owner's own.
        if (! $project->started_here || $project->featureRequests()->whereNotNull('accepted_at')->exists()) {
            return null;
        }

        // Tries again carry the same words, so the newest one decides.
        $change = $project->featureRequests()
            ->whereNull('parent_id')
            ->where('prompt', 'like', __('Make the first version:').'%')
            ->latest('id')
            ->first();

        if (! $change instanceof FeatureRequest) {
            return null;
        }

        $described = $this->describeFeatureRequest->handle($change);
        $state = match (true) {
            $change->latestRun?->question !== null => 'asking',
            // Made, then stopped in the checks or the review, as the chat
            // shows it.
            $described['featureRequest']['stopped'], RetryFeatureRequest::stoppedWhileChecking($change) => 'stopped',
            // Ready once it can be kept: the checks have passed, and the
            // pane opens the app with it as for any change to decide on.
            $described['featureRequest']['can_accept'] => 'ready',
            default => 'making',
        };

        return [
            'change' => $change->uuid,
            'state' => $state,
            'error' => $state === 'stopped' ? ($described['featureRequest']['error'] ?? $described['run']['error'] ?? null) : null,
            'can_retry' => $state === 'stopped' && $described['featureRequest']['can_retry'],
        ];
    }
}
