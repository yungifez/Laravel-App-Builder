<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VisualEditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to how one element looks, or where it sits among its siblings,
 * made in the inspector and committed without a model call.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $experiment_id The idea it was made in; null is the main app
 * @property int $user_id
 * @property string $file
 * @property int $line
 * @property int $column
 * @property string $tag
 * @property string $device "base", "md" or "lg"
 * @property array<string, int|float|string|array<string, string>|null> $changes The properties set, in pixels and words, or the move
 * @property string $classes_before
 * @property string $classes_after
 * @property string $base_revision
 * @property string $commit_sha
 * @property string|null $revert_sha
 * @property CarbonImmutable|null $reverted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['experiment_id', 'user_id', 'file', 'line', 'column', 'tag', 'device', 'changes', 'classes_before', 'classes_after', 'base_revision', 'commit_sha', 'revert_sha', 'reverted_at'])]
class VisualEdit extends Model
{
    /** @use HasFactory<VisualEditFactory> */
    use HasFactory;

    /**
     * The key in "changes" of a move: where the element went, relative to
     * which sibling. The element's line and column are where it is after.
     */
    public const MOVE = 'move';

    /**
     * The key in "changes" of new words: the words before and after.
     */
    public const TEXT = 'text';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'line' => 'integer',
            'column' => 'integer',
            'reverted_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the edit moved the element instead of changing how
     * it looks.
     */
    public function moves(): bool
    {
        // Inside the model, $this->changes is Eloquent's own change tracking.
        return isset($this->getAttribute('changes')[self::MOVE]);
    }

    /**
     * Determine whether the edit changed the words the element shows.
     */
    public function rewords(): bool
    {
        return isset($this->getAttribute('changes')[self::TEXT]);
    }

    /**
     * Get what kind of edit this is: a change to how the element looks, a
     * move, or new words.
     */
    public function kind(): string
    {
        return match (true) {
            $this->moves() => 'move',
            $this->rewords() => 'text',
            default => 'look',
        };
    }

    /**
     * Get the idea the edit was made in, if not the main app.
     *
     * @return BelongsTo<Experiment, $this>
     */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    /**
     * Get the branch the edit lives on now, or null when its idea was
     * thrown away.
     */
    public function branch(): ?string
    {
        return Experiment::branchOf($this->experiment);
    }

    /**
     * Get the project the edit changed.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the owner who made the edit.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
