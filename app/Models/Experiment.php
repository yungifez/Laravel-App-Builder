<?php

namespace App\Models;

use App\Enums\ExperimentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExperimentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An idea the owner tries on its own branch of the project's repository.
 * Changes made in it stay off the main app until the owner uses the idea
 * (a merge into the main branch) or throws it away (the branch is deleted).
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string $name
 * @property string $branch
 * @property string $base_sha
 * @property ExperimentStatus $status
 * @property string|null $merge_sha
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'name', 'branch', 'base_sha', 'status', 'merge_sha', 'finished_at'])]
class Experiment extends Model
{
    /** @use HasFactory<ExperimentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExperimentStatus::class,
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the name of the main branch: the app itself.
     */
    public static function mainBranch(): string
    {
        return (string) config('builder.projects.branch');
    }

    /**
     * Get the branch that work made in an idea (or in the main app, for
     * null) lives on now: the idea's own while it is open, the main branch
     * once it is used, and none once it is thrown away.
     */
    public static function branchOf(?self $experiment): ?string
    {
        return match ($experiment?->status) {
            null, ExperimentStatus::Merged => self::mainBranch(),
            ExperimentStatus::Open => $experiment->branch,
            ExperimentStatus::Discarded => null,
        };
    }

    /**
     * Get the project the idea is for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the changes asked for in the idea.
     *
     * @return HasMany<FeatureRequest, $this>
     */
    public function featureRequests(): HasMany
    {
        return $this->hasMany(FeatureRequest::class);
    }
}
