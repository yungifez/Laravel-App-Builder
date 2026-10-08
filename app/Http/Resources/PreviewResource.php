<?php

namespace App\Http\Resources;

use App\Models\Preview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A preview of a change, for its owner.
 *
 * @mixin Preview
 */
class PreviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'error' => $this->error,
            'url' => $this->url(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
