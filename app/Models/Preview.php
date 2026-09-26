<?php

namespace App\Models;

use App\Enums\PreviewStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PreviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A running copy of the project, served on its own host so it cannot share
 * the control plane's origin. It runs a feature request's change, or, with
 * no feature request, the project at a revision. An editable preview marks
 * each element with its source, for point-and-edit.
 *
 * Owners reach it through a short-lived, single-use grant that becomes a
 * session cookie scoped to the preview host.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $feature_request_id
 * @property int|null $workspace_id
 * @property string|null $revision The project commit a project preview runs
 * @property bool $editable Whether elements carry their source location
 * @property string $host The preview's subdomain label
 * @property PreviewStatus $status
 * @property int|null $port
 * @property string|null $upstream_url
 * @property string|null $grant_hash
 * @property CarbonImmutable|null $grant_expires_at
 * @property string|null $session_hash
 * @property CarbonImmutable|null $session_expires_at
 * @property string|null $error
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $rebuilt_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $stopped_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['project_id', 'feature_request_id', 'revision', 'editable', 'rebuilt_at', 'workspace_id', 'host', 'status', 'port', 'upstream_url', 'grant_hash', 'grant_expires_at', 'session_hash', 'session_expires_at', 'error', 'ready_at', 'last_seen_at', 'expires_at', 'stopped_at'])]
class Preview extends Model
{
    /** @use HasFactory<PreviewFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PreviewStatus::class,
            'port' => 'integer',
            'grant_expires_at' => 'datetime',
            'session_expires_at' => 'datetime',
            'editable' => 'boolean',
            'ready_at' => 'datetime',
            'rebuilt_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    /**
     * Get the project the preview runs.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the feature request whose change the preview runs, if any.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the workspace the preview runs in.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the preview's public URL.
     */
    public function url(string $path = '/'): string
    {
        $port = config('builder.preview.public_port');

        return config('builder.preview.scheme').'://'.$this->host.'.'.config('builder.preview.domain')
            .(filled($port) ? ":{$port}" : '')
            .'/'.ltrim($path, '/');
    }
}
