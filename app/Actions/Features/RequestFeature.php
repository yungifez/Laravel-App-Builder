<?php

namespace App\Actions\Features;

use App\Enums\FeatureRequestStatus;
use App\Features\FeatureGeneratorManager;
use App\Jobs\GenerateFeature;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;

class RequestFeature
{
    public function __construct(private FeatureGeneratorManager $generators) {}

    /**
     * Record the owner's feature request and queue its generation.
     */
    public function handle(Project $project, User $requester, string $prompt): FeatureRequest
    {
        $featureRequest = $project->featureRequests()->create([
            'user_id' => $requester->id,
            'prompt' => $prompt,
            'status' => FeatureRequestStatus::Generating,
            'generator' => $this->generators->getDefaultDriver(),
        ]);

        GenerateFeature::dispatch($featureRequest);

        return $featureRequest;
    }
}
