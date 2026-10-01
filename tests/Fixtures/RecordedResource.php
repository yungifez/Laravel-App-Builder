<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An answer of the app RecordedApp stands in for. It reads while the
 * answer is built.
 */
class RecordedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['users' => User::query()->count()];
    }
}
