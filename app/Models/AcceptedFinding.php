<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AcceptedFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A boundary finding the owner said a change does on purpose (direction 33,
 * an exception). It covers one finding by what it is, never a whole rule,
 * and only the change it was given for. Once the change is kept, the
 * finding is part of the app as it was, so a later change is held only to
 * what it adds of the same kind: that is when it is asked about again.
 *
 * @property int $id
 * @property int $feature_request_id
 * @property int|null $user_id The person who said so
 * @property string $kind The rule, as AppBoundaries names its findings
 * @property string $identity What the finding is, as BoundaryCode::identity() names it
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'kind', 'identity'])]
class AcceptedFinding extends Model
{
    /** @use HasFactory<AcceptedFindingFactory> */
    use HasFactory;

    /**
     * Get the change the finding was accepted for.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the person who accepted it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
