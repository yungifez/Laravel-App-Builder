<?php

namespace App\Http\Resources;

use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A feature request with its generated change and steps.
 *
 * @mixin FeatureRequest
 */
class FeatureRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parent = $this->parent;

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'error' => $this->error,
            'target_step' => $parent === null || $this->target_step === null
                ? null
                : $parent->step($this->target_step),
            'steps' => $this->steps ?? [],
            'files' => PatchSummary::files($this->patch),
        ];
    }
}
