<?php

namespace App\Models;

use App\Enums\OperationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A journal entry for one tool call, keyed by the caller's operation key.
 *
 * The entry is written before the tool runs. Repeating a key with the same
 * payload returns the recorded outcome instead of running the tool again.
 *
 * @property int $id
 * @property int $run_id
 * @property string $operation_key
 * @property string $tool
 * @property array<string, mixed> $arguments
 * @property string $payload_hash
 * @property int $fencing_token
 * @property int|null $expected_revision
 * @property OperationStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['operation_key', 'tool', 'arguments', 'payload_hash', 'fencing_token', 'expected_revision', 'status', 'result', 'error', 'started_at', 'finished_at'])]
class RunOperation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'fencing_token' => 'integer',
            'expected_revision' => 'integer',
            'status' => OperationStatus::class,
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the run the operation belongs to.
     *
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
