<?php

namespace App\Models;

use Database\Factories\ProjectNoteRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One write to a project's notes file, kept so the notes have a history
 * like the code does (architecture §29.3).
 *
 * @property int $id
 * @property int $project_id
 * @property string $branch
 * @property string $path
 * @property string|null $contents What the file said after the write; null when it was removed
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'branch', 'path', 'contents', 'user_id'])]
class ProjectNoteRevision extends Model
{
    /** @use HasFactory<ProjectNoteRevisionFactory> */
    use HasFactory;

    /**
     * Get the project whose notes changed.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the person who made the write.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
