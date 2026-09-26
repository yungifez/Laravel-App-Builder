<?php

namespace App\Models;

use Database\Factories\DecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A typed decision about a change request, made by a cheap decision model
 * before any expensive work (architecture §26.9). In shadow mode it never
 * acts: it is kept to compare with what actually happened.
 *
 * @property int $id
 * @property int $feature_request_id
 * @property string $name
 * @property string $driver The provider that decided
 * @property string|null $model
 * @property string $choice
 * @property array<string, float> $probabilities
 * @property float $confidence
 * @property float $threshold The confidence it needs before it may act
 * @property bool $acted
 * @property int $latency_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'driver', 'model', 'choice', 'probabilities', 'confidence', 'threshold', 'acted', 'latency_ms'])]
class Decision extends Model
{
    /** @use HasFactory<DecisionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'probabilities' => 'array',
            'confidence' => 'float',
            'threshold' => 'float',
            'acted' => 'boolean',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * Get the change request the decision is about.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Determine if the decision was sure enough to act on.
     */
    public function confident(): bool
    {
        return $this->confidence >= $this->threshold;
    }
}
