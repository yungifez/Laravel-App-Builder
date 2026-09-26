<?php

namespace App\Models;

use App\Enums\BoxCommandStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * One command for a runner in a workspace box: run a program, write or read
 * a file, unpack the project. The payload can hold credentials for one
 * command, so it is encrypted and removed once the command ends.
 *
 * @property string $id
 * @property string $runner
 * @property string $box
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property int $timeout_seconds
 * @property BoxCommandStatus $status
 * @property array<string, mixed>|null $result
 * @property Carbon|null $claimed_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['runner', 'box', 'type', 'payload', 'timeout_seconds', 'status', 'result', 'claimed_at', 'cancel_requested_at', 'finished_at'])]
#[Hidden(['payload'])]
class BoxCommand extends Model
{
    use HasUlids, Prunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'status' => BoxCommandStatus::class,
            'result' => 'array',
            'claimed_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Commands that ended a day ago are only history.
     *
     * @return Builder<BoxCommand>
     */
    public function prunable(): Builder
    {
        return static::whereNotNull('finished_at')->where('finished_at', '<', now()->subDay());
    }
}
