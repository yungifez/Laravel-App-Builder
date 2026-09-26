<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rebuild of an editable preview to the project's latest commit. A
 * rebuild takes in every commit made before it started, so the time from a
 * visual edit to the first rebuild that started after it is how long the
 * owner waited to see the edit.
 *
 * @property int $id
 * @property int $preview_id
 * @property int $project_id
 * @property string $from_revision
 * @property string $to_revision
 * @property string $status "running", "rebuilt" or "failed"
 * @property string|null $error
 * @property CarbonImmutable|null $queued_at When the rebuild was asked for
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['preview_id', 'project_id', 'from_revision', 'to_revision', 'status', 'error', 'queued_at', 'started_at', 'finished_at'])]
class PreviewRebuild extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the preview that was rebuilt.
     *
     * @return BelongsTo<Preview, $this>
     */
    public function preview(): BelongsTo
    {
        return $this->belongsTo(Preview::class);
    }

    /**
     * Get the project the preview shows.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
