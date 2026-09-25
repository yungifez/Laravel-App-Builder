<?php

namespace App\Actions\Features;

use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Features\FeatureGeneratorManager;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;

class RequestFeature
{
    public function __construct(
        private FeatureGeneratorManager $generators,
        private StartRun $startRun,
    ) {}

    /**
     * Record the owner's feature request and start a run to build it.
     */
    public function handle(Project $project, User $requester, string $prompt): FeatureRequest
    {
        $featureRequest = $project->featureRequests()->create([
            'user_id' => $requester->id,
            'prompt' => $prompt,
            'status' => FeatureRequestStatus::Generating,
            'generator' => $this->generators->getDefaultDriver(),
        ]);

        $this->startRun->handle($featureRequest);

        return $featureRequest;
    }
}
