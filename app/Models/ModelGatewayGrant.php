<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * What one run's gateway token opens, and what the agent spent through it.
 * It is kept in the database, so a cache that is emptied or restarted never
 * stops a run half way, and what each run spent can still be read after.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $provider
 * @property int $requests
 * @property int $input_tokens
 * @property int $output_tokens
 * @property Carbon $expires_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['token_hash', 'provider', 'expires_at', 'closed_at'])]
class ModelGatewayGrant extends Model
{
    use Prunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requests' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Get the prunable model query: grants that ended a month ago.
     *
     * @return Builder<ModelGatewayGrant>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subMonth());
    }
}
