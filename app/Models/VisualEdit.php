<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VisualEditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to how one element looks, made in the inspector and committed
 * without a model call.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string $file
 * @property int $line
 * @property int $column
 * @property string $tag
 * @property string $device "base", "md" or "lg"
 * @property array<string, int|float|string|null> $changes The properties set, in pixels and words
 * @property string $classes_before
 * @property string $classes_after
 * @property string $base_revision
 * @property string $commit_sha
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'file', 'line', 'column', 'tag', 'device', 'changes', 'classes_before', 'classes_after', 'base_revision', 'commit_sha'])]
class VisualEdit extends Model
{
    /** @use HasFactory<VisualEditFactory> */
    use HasFactory;

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
        ];
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
