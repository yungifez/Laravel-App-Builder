<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One publish of a project: the full checks on one commit, a push of that
 * commit to the branch the hosting platform deploys from, then checks that
 * the app answers at its address.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string $commit_sha
 * @property int|null $restores_deployment_id The earlier publish this one puts back online
 * @property string|null $release_sha The commit sent to the host, when it is not commit_sha
 * @property string|null $backup_id The host's copy of the app's information, saved before this release changed how it is stored
 * @property string $branch
 * @property string|null $host The host it was published to
 * @property string|null $host_release_id The host's own ID for this release, when it has one
 * @property string|null $host_status The host's last word on the release, for operators
 * @property DeploymentStatus $status
 * @property list<array{name: string, passed: bool, output?: string}>|null $checks What a failed check said is for the builder, never the owner
 * @property string|null $error
 * @property 'settings'|'ours'|null $error_cause Who can put the failure right: the owner's publishing settings, or us
 * @property string|null $error_details What the host or Git said behind the failure, for Details only
 * @property CarbonImmutable|null $pushed_at
 * @property CarbonImmutable|null $confirmed_at When the app answered its checks at its address
 * @property list<array{path: string, status: int|null, passed: bool, key?: string}>|null $health The latest checks of the app's address, sign-in included
 * @property list<array{class: string|null, message: string, count: int, last_at: string}>|null $live_errors Errors the host saw while this version was online, grouped by kind
 * @property CarbonImmutable|null $live_errors_checked_at Up to when the host was asked for errors
 * @property CarbonImmutable|null $data_loss_confirmed_at When the owner said yes to a release that deletes or reshapes information the live app keeps
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'commit_sha', 'restores_deployment_id', 'release_sha', 'backup_id', 'data_loss_confirmed_at', 'branch', 'host', 'host_release_id', 'host_status', 'status', 'checks', 'error', 'error_cause', 'error_details', 'pushed_at', 'confirmed_at', 'health', 'live_errors', 'live_errors_checked_at', 'finished_at'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'checks' => 'array',
            'pushed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'health' => 'array',
            'live_errors' => 'array',
            'live_errors_checked_at' => 'datetime',
            'data_loss_confirmed_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Count the errors the host saw while this version was online.
     */
    public function liveErrorCount(): int
    {
        return (int) array_sum(array_column($this->live_errors ?? [], 'count'));
    }

    /**
     * Get the commit the host was sent: the checked commit, or the same
     * files on top of what the host had.
     */
    public function released(): string
    {
        return $this->release_sha ?? $this->commit_sha;
    }

    /**
     * Get the earlier publish this one puts back online.
     *
     * @return BelongsTo<Deployment, $this>
     */
    public function restores(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'restores_deployment_id');
    }

    /**
     * Get the project that was published.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the owner who published it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the kept changes this publish contains, as recorded when it began.
     *
     * @return BelongsToMany<FeatureRequest, $this>
     */
    public function featureRequests(): BelongsToMany
    {
        return $this->belongsToMany(FeatureRequest::class);
    }
}
