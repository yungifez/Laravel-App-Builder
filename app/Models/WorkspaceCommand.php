<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property list<string> $command
 * @property int $exit_code
 * @property bool $timed_out
 * @property int $duration_ms
 * @property string $output
 * @property string $error_output
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['command', 'exit_code', 'timed_out', 'duration_ms', 'output', 'error_output'])]
class WorkspaceCommand extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => 'array',
            'timed_out' => 'boolean',
        ];
    }

    /**
     * Get the workspace the command ran in.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
