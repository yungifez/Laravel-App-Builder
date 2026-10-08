<?php

namespace App\Models;

use App\Actions\Workspaces\RunWorkspaceCommand;
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
 * @property bool $lost No runner took the command, or it never answered
 * @property int $duration_ms
 * @property string $output
 * @property string $error_output
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['command', 'exit_code', 'timed_out', 'lost', 'duration_ms', 'output', 'error_output'])]
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
            'lost' => 'boolean',
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

    /**
     * Determine if the beginning of the command's output was cut off.
     */
    public function outputTruncated(): bool
    {
        return str_starts_with($this->output, RunWorkspaceCommand::TRUNCATION_MARKER);
    }
}
