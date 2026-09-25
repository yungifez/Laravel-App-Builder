<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in a run's append-only log, numbered per run.
 *
 * @property int $id
 * @property int $run_id
 * @property int $sequence
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['sequence', 'type', 'data'])]
class RunEvent extends Model
{
    /**
     * Events are never updated, so there is no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'data' => 'array',
        ];
    }

    /**
     * Get the run the event belongs to.
     *
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
