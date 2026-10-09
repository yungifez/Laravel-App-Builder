<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
     * Why work goes back to be fixed: the checks failed, or the second look
     * found something.
     */
    public const SENT_BACK = ['verification_failed', 'review_findings'];

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

    /**
     * Keep the moments work was sent back to be fixed: each is a problem
     * caught before the owner saw the change.
     *
     * @param  Builder<RunEvent>  $query
     */
    #[Scope]
    protected function sentBack(Builder $query): void
    {
        $query->where('type', 'status')
            ->where('data->to', RunStatus::Implementing->value)
            ->whereIn('data->reason', self::SENT_BACK);
    }
}
