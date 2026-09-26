<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One publish of a project: the full checks on one commit, then a push of
 * that commit to the branch the hosting platform deploys from.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string $commit_sha
 * @property string $branch
 * @property DeploymentStatus $status
 * @property list<array{name: string, passed: bool}>|null $checks
 * @property string|null $error
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'commit_sha', 'branch', 'status', 'checks', 'error', 'finished_at'])]
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
}
