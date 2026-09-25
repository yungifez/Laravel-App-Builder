<?php

namespace App\Jobs;

use App\Enums\FeatureRequestStatus;
use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\FeatureGeneratorManager;
use App\Models\FeatureRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateFeature implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public FeatureRequest $featureRequest) {}

    /**
     * Generate the change for the feature request with its generator.
     */
    public function handle(FeatureGeneratorManager $generators): void
    {
        try {
            $change = $generators->driver($this->featureRequest->generator)->generate($this->featureRequest);
        } catch (CannotGenerateFeature $exception) {
            $this->featureRequest->update([
                'status' => FeatureRequestStatus::Failed,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $this->featureRequest->update([
            'status' => FeatureRequestStatus::Generated,
            'solution_key' => $change->solutionKey,
            'summary' => $change->summary,
            'patch' => $change->patch,
            'steps' => $change->steps,
            'error' => null,
        ]);
    }

    /**
     * Record an unexpected failure on the request so the owner sees it.
     */
    public function failed(?Throwable $exception): void
    {
        $this->featureRequest->update([
            'status' => FeatureRequestStatus::Failed,
            'error' => __('Generation failed unexpectedly.'),
        ]);
    }
}
