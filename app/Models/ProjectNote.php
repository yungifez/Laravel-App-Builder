<?php

namespace App\Models;

use Database\Factories\ProjectNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One file of what we know about a project's product, for one line of work
 * (the main branch or an idea's). The notes live here, not in the app's
 * repository: workspaces get a copy, and changes come back through
 * accepted runs and the owner's edits.
 *
 * @property int $id
 * @property int $project_id
 * @property string $branch
 * @property string $path Relative to the notes directory, for example "capabilities/teams.md"
 * @property string $contents
 * @property string|null $base_hash The contents' sha1 when an idea copied it from the main app
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'branch', 'path', 'contents', 'base_hash'])]
class ProjectNote extends Model
{
    /** @use HasFactory<ProjectNoteFactory> */
    use HasFactory;

    /**
     * Get the project the notes describe.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
