<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\DeveloperReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The owner asks one of our own developers to look at a change or at the
 * whole app (architecture §29.3). The developer answers from operations,
 * reading what we wrote for them and the code. Guidance the owner keeps
 * joins the app's notes, so later changes follow it.
 *
 * @property int $id
 * @property string $uuid Names the row in links and requests
 * @property int $project_id
 * @property int $user_id
 * @property int|null $feature_request_id The change asked about; null asks about the whole app
 * @property string $question
 * @property string|null $revision The commit the developer's copy of the code is taken from
 * @property string $bundle What the developer reads, in Markdown
 * @property int|null $answered_by The developer of ours who answered
 * @property array{summary: string, findings: list<string>, guidance: list<string>}|null $answer
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $guidance_kept_at
 * @property CarbonImmutable|null $withdrawn_at The owner took the question back before it was answered
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'feature_request_id', 'question', 'revision', 'bundle', 'answered_by', 'answer', 'answered_at', 'guidance_kept_at', 'withdrawn_at'])]
class DeveloperReview extends Model
{
    /** @use HasFactory<DeveloperReviewFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answer' => 'array',
            'answered_at' => 'datetime',
            'guidance_kept_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * Get the project the review is about.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the change the review is about, if any.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the developer of ours who answered.
     *
     * @return BelongsTo<User, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /**
     * Determine if the question waits for a developer.
     */
    public function waiting(): bool
    {
        return $this->answered_at === null && $this->withdrawn_at === null;
    }
}
