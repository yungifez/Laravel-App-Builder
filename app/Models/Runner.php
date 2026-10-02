<?php

namespace App\Models;

use Database\Factories\RunnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A runner in the pool: a machine, usually a small VM hosted apart from the
 * control plane, that holds many workspaces. It connects out with its own
 * token, which is kept only as a hash, and reports the address its services
 * (previews) are reached at.
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property string|null $service_host
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'token_hash', 'service_host', 'last_seen_at'])]
#[Hidden(['token_hash'])]
class Runner extends Model
{
    /** @use HasFactory<RunnerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Hash a runner's token the way it is stored. Tokens are long and
     * random, so a plain SHA-256 is enough to find and check them.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Scope the query to runners that asked for work recently.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnline(Builder $query): void
    {
        $query->where('last_seen_at', '>=', now()->subSeconds((int) config('workspaces.boxes.pool.online_seconds')));
    }
}
