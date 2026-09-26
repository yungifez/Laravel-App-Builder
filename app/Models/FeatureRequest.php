<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use Database\Factories\FeatureRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An owner's request for a feature, or for a change to one step of a feature
 * generated earlier (a follow-up with a parent and a target step).
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $prompt
 * @property array{file: string, line: int, column: int, tag: string, text: string|null, area: string|null}|null $selection The element the owner pointed at in the preview
 * @property string|null $target_step
 * @property FeatureRequestStatus $status
 * @property string $generator
 * @property string|null $base_revision The project commit the change is built on; null builds on the project's source directory
 * @property string|null $solution_key
 * @property string|null $summary
 * @property string|null $patch
 * @property list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>|null $steps
 * @property list<string>|null $acceptance Protected acceptance test files that apply to the change
 * @property string|null $error
 * @property string|null $commit_sha The project commit that holds the change once the owner accepted it
 * @property Carbon|null $accepted_at
 * @property string|null $revert_sha The project commit that undid the change
 * @property Carbon|null $reverted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'user_id', 'parent_id', 'prompt', 'selection', 'target_step', 'status', 'generator', 'solution_key', 'summary', 'patch', 'steps', 'acceptance', 'error', 'base_revision', 'commit_sha', 'accepted_at', 'revert_sha', 'reverted_at'])]
class FeatureRequest extends Model
{
    /**
     * Where a workspace keeps the patches of earlier changes while applying
     * them. It is removed before the workspace is used; `.builder/` itself
     * belongs to the application (its project context).
     */
    public const LINEAGE_DIRECTORY = '.builder-lineage';

    /** @use HasFactory<FeatureRequestFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FeatureRequestStatus::class,
            'steps' => 'array',
            'acceptance' => 'array',
            'selection' => 'array',
            'accepted_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    /**
     * Get the request as the planner, coder and reviewer read it: the
     * owner's words and, when they started from the preview, the element
     * they pointed at.
     */
    public function instructions(): string
    {
        $selection = $this->selection;

        if ($selection === null) {
            return $this->prompt;
        }

        $element = "`<{$selection['tag']}>` at {$selection['file']}:{$selection['line']}";
        $text = filled($selection['text'] ?? null) ? ' (it shows "'.str($selection['text'])->squish()->limit(120).'")' : '';
        $area = filled($selection['area'] ?? null) ? " It belongs to the area \"{$selection['area']}\"." : '';

        return "{$this->prompt}\n\nThe owner pointed at this element in the app: {$element}{$text}.{$area}";
    }

    /**
     * Get the project the request is for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the request this one follows up on.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the follow-up requests made on this one.
     *
     * @return HasMany<FeatureRequest, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get the verification runs for the request's change.
     *
     * @return HasMany<Verification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /**
     * Get the construction runs for the request.
     *
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Get the request's most recent construction run.
     *
     * @return HasOne<Run, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(Run::class)->latestOfMany();
    }

    /**
     * Get the request's previews.
     *
     * @return HasMany<Preview, $this>
     */
    public function previews(): HasMany
    {
        return $this->hasMany(Preview::class);
    }

    /**
     * Get this request and the requests it follows up on that are not part of
     * its base revision, oldest first, so their patches can be applied in
     * order on top of it. A request made after its parent was accepted is
     * based on the commit that holds the parent, so the lineage stops there.
     *
     * @return list<FeatureRequest>
     */
    public function lineage(): array
    {
        $lineage = [$this];
        $current = $this;

        while ($current->parent_id !== null) {
            $current = $current->parent()->firstOrFail();

            if ($current->base_revision !== $this->base_revision) {
                break;
            }

            array_unshift($lineage, $current);
        }

        return $lineage;
    }

    /**
     * Determine whether the owner accepted the change into the project and
     * has not undone it.
     */
    public function isAccepted(): bool
    {
        return $this->commit_sha !== null && $this->reverted_at === null;
    }

    /**
     * Find one of the generated change's steps by key.
     *
     * @return array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}|null
     */
    public function step(string $key): ?array
    {
        return collect($this->steps ?? [])->firstWhere('key', $key);
    }
}
