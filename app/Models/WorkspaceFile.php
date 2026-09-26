<?php

namespace App\Models;

use Database\Factories\WorkspaceFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file a project's workspaces need that is never part of its code, such
 * as `.env`. The first workspace makes it; every later one gets this copy,
 * so a workspace can be thrown away at any time.
 *
 * @property int $id
 * @property int $project_id
 * @property string $path
 * @property string $contents Encrypted at rest: it can hold secrets
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'path', 'contents'])]
class WorkspaceFile extends Model
{
    /** @use HasFactory<WorkspaceFileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contents' => 'encrypted',
        ];
    }

    /**
     * Get the project the file belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
