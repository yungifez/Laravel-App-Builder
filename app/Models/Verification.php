<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Database\Factories\VerificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A run of the project's checks against a feature request's change, applied
 * on top of every change it follows up on.
 *
 * @property int $id
 * @property int $feature_request_id
 * @property int|null $run_id
 * @property int|null $workspace_id
 * @property VerificationStatus $status
 * @property list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>|null $results
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['run_id', 'workspace_id', 'status', 'results', 'error', 'started_at', 'finished_at'])]
class Verification extends Model
{
    /** @use HasFactory<VerificationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'results' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the feature request being verified.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the construction run that asked for the verification, if any.
     *
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
