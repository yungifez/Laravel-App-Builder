<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FindingProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The agent's case that a finding of the gate should stand (direction 33):
 * the finding is wrong, or it is what the owner asked for. The agent can
 * only ask. The finding holds the change back until the owner agrees, and
 * once the owner says no, the agent cannot ask again for that change.
 *
 * @property int $id
 * @property int $feature_request_id
 * @property int|null $run_id The run whose agent asked
 * @property string $kind The rule, as AppBoundaries names its findings
 * @property string $identity What the finding is, as BoundaryCode::identity() names it
 * @property string $reason The agent's reason, in its own words
 * @property bool|null $agreed Null until the owner answers
 * @property int|null $answered_by
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['run_id', 'kind', 'identity', 'reason', 'agreed', 'answered_by', 'answered_at'])]
class FindingProposal extends Model
{
    /** @use HasFactory<FindingProposalFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agreed' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Get the change the finding was found in.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the run whose agent asked.
     *
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
