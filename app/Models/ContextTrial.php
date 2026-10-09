<?php

namespace App\Models;

use App\Enums\ContextMode;
use Database\Factories\ContextTrialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change made for the context experiment (architecture §26.7): a
 * request of the benchmark on the project's current commit, with the agent
 * given its context one way. The trials of a round share the request and
 * the commit, so they compare as pairs.
 *
 * @property int $id
 * @property int $project_id
 * @property int $round
 * @property string $prompt
 * @property ContextMode $mode
 * @property int $feature_request_id
 * @property string $outcome One of the OUTCOME_ constants
 * @property array{first_attempt_passed: bool|null, verified: bool, tokens: int, cost_usd: float, unpriced_calls: int, tool_calls: int, minutes: float|null, repairs: int, unexpected_areas: int|null} $measures
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'round', 'prompt', 'mode', 'feature_request_id', 'outcome', 'measures'])]
class ContextTrial extends Model
{
    /** @use HasFactory<ContextTrialFactory> */
    use HasFactory;

    /** The change was built, checked and reviewed. */
    public const OUTCOME_COMPLETED = 'completed';

    /** The change failed, was cancelled or waited for the owner. */
    public const OUTCOME_STOPPED = 'stopped';

    /** The change took longer than the experiment waits. */
    public const OUTCOME_TIMED_OUT = 'timed_out';

    /** The agent got its context another way than the trial asked, so it measures nothing. */
    public const OUTCOME_WRONG_MODE = 'wrong_mode';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'mode' => ContextMode::class,
            'measures' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }
}
