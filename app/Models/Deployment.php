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
 * @property string $branch
 * @property string|null $host The host it was published to
 * @property string|null $host_release_id The host's own ID for this release, when it has one
 * @property string|null $host_status The host's last word on the release, for operators
 * @property DeploymentStatus $status
 * @property list<array{name: string, passed: bool}>|null $checks
 * @property string|null $error
 * @property CarbonImmutable|null $pushed_at
 * @property CarbonImmutable|null $confirmed_at When the app answered its checks at its address
 * @property list<array{path: string, status: int|null, passed: bool}>|null $health The latest checks of the app's address
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'commit_sha', 'branch', 'host', 'host_release_id', 'host_status', 'status', 'checks', 'error', 'pushed_at', 'confirmed_at', 'health', 'finished_at'])]
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
            'finished_at' => 'datetime',
        ];
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
