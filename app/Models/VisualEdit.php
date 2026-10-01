<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use App\Models\Concerns\HasPublicId;
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
 * @property string $uuid Names the row in links and requests
 * @property int $project_id
 * @property int|null $experiment_id The idea it was made in; null is the main app
 * @property int|null $feature_request_id The change it was made on while that waited to be kept
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
#[Fillable(['experiment_id', 'feature_request_id', 'user_id', 'file', 'line', 'column', 'tag', 'device', 'changes', 'classes_before', 'classes_after', 'base_revision', 'commit_sha', 'revert_sha', 'reverted_at'])]
class VisualEdit extends Model
{
    /** @use HasFactory<VisualEditFactory> */
    use HasFactory;

    use HasPublicId;

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
     * The key in "changes" of a new address for a link: the address before
     * and after.
     */
    public const LINK = 'link';

    /**
     * The key in "changes" of a copy of the element put right after it:
     * where the original was. The line and column are the copy's.
     */
    public const DUPLICATE = 'duplicate';

    /**
     * The key in "changes" of an element taken out of the page: where it
     * was. The line and column are the element that held it.
     */
    public const REMOVE = 'remove';

    /**
     * The key in "changes" of a new part put right after an element: where
     * that element was, and what kind of part (see NewPart). The line and
     * column are the new part's.
     */
    public const ADD = 'add';

    /**
     * The key in "changes" of a new picture: the file shown before and
     * after, as the app serves them.
     */
    public const PICTURE = 'picture';

    /**
     * The key in "changes" of a new theme colour for the whole app: which
     * look (light or dark), which colour, and its value before and after.
     * The file, line and look's selector are where it is written.
     */
    public const THEME = 'theme';

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
     * Determine whether the edit changed where a link goes.
     */
    public function relinks(): bool
    {
        return isset($this->getAttribute('changes')[self::LINK]);
    }

    /**
     * Determine whether the edit put a new picture in.
     */
    public function repictures(): bool
    {
        return isset($this->getAttribute('changes')[self::PICTURE]);
    }

    /**
     * Determine whether the edit changed one of the app's theme colours.
     */
    public function rethemes(): bool
    {
        return isset($this->getAttribute('changes')[self::THEME]);
    }

    /**
     * Determine whether the edit copied the element, added a part after it,
     * or took it out.
     */
    public function reshapes(): bool
    {
        return isset($this->getAttribute('changes')[self::DUPLICATE])
            || isset($this->getAttribute('changes')[self::ADD])
            || isset($this->getAttribute('changes')[self::REMOVE]);
    }

    /**
     * Determine whether undoing or redoing the edit puts back the whole
     * file, rather than only the element's classes.
     */
    public function rewritesFile(): bool
    {
        return $this->moves() || $this->rewords() || $this->relinks() || $this->repictures() || $this->rethemes() || $this->reshapes();
    }

    /**
     * Get what kind of edit this is: a change to how the element looks, a
     * move, new words, a new address for a link, a new picture, a copy, a
     * new part, or taking it out.
     */
    public function kind(): string
    {
        return match (true) {
            $this->moves() => 'move',
            $this->rewords() => 'text',
            $this->relinks() => 'link',
            $this->repictures() => 'picture',
            $this->rethemes() => 'theme',
            isset($this->getAttribute('changes')[self::DUPLICATE]) => 'duplicate',
            isset($this->getAttribute('changes')[self::ADD]) => 'add',
            isset($this->getAttribute('changes')[self::REMOVE]) => 'remove',
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
     * Get the change the edit was made on, if any.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the branch the edit lives on now, or null when its idea was
     * thrown away. An edit on a change lives on the change's design branch
     * only while the change waits; once kept, it is in the change's commit.
     */
    public function branch(): ?string
    {
        if ($this->featureRequest !== null) {
            return $this->featureRequest->status === FeatureRequestStatus::Generated ? $this->featureRequest->designBranch() : null;
        }

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
