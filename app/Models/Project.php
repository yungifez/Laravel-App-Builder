<?php

namespace App\Models;

use App\Enums\NotesDraftStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer application the builder generates features for.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $source_path
 * @property string|null $deploy_remote The Git remote the hosting platform deploys from, credentials included
 * @property string|null $deploy_branch
 * @property NotesDraftStatus|null $notes_draft_status
 * @property int|null $experiment_id The idea the owner is working in; null is the main app
 * @property array{purpose: string, areas: list<array{key: string, name: string, summary: string, paths: list<string>, behaviors: list<array{key: string, name: string}>, rules: list<string>}>}|null $notes_draft Notes a model drafted from an imported app, waiting for the owner
 * @property string|null $notes_draft_error
 * @property list<array{role: string, provider: string|null, model: string|null, input_tokens: int, output_tokens: int, cost_usd: float|null}>|null $setup_model_calls Model calls made to set the project up, outside any change
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'source_path', 'experiment_id', 'deploy_remote', 'deploy_branch', 'notes_draft_status', 'notes_draft', 'notes_draft_error', 'setup_model_calls'])]
#[Hidden(['deploy_remote'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deploy_remote' => 'encrypted',
            'notes_draft_status' => NotesDraftStatus::class,
            'notes_draft' => 'array',
            'setup_model_calls' => 'array',
        ];
    }

    /**
     * Determine if the project is connected to a place to publish to.
     */
    public function publishable(): bool
    {
        return $this->deploy_remote !== null && $this->deploy_branch !== null;
    }

    /**
     * Get the user who owns the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the idea the owner is working in, if not the main app.
     *
     * @return BelongsTo<Experiment, $this>
     */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    /**
     * Get the ideas tried for the project.
     *
     * @return HasMany<Experiment, $this>
     */
    public function experiments(): HasMany
    {
        return $this->hasMany(Experiment::class);
    }

    /**
     * Get the branch the owner is working on: the open idea's, or the
     * main branch.
     */
    public function branch(): string
    {
        return Experiment::branchOf($this->experiment) ?? Experiment::mainBranch();
    }

    /**
     * Get the feature requests made for the project.
     *
     * @return HasMany<FeatureRequest, $this>
     */
    public function featureRequests(): HasMany
    {
        return $this->hasMany(FeatureRequest::class);
    }

    /**
     * Get the project's previews, including those of its feature requests.
     *
     * @return HasMany<Preview, $this>
     */
    public function previews(): HasMany
    {
        return $this->hasMany(Preview::class);
    }

    /**
     * Get the changes owners made in the inspector.
     *
     * @return HasMany<VisualEdit, $this>
     */
    public function visualEdits(): HasMany
    {
        return $this->hasMany(VisualEdit::class);
    }

    /**
     * Get what we know about the product, for every line of work.
     *
     * @return HasMany<ProjectNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ProjectNote::class);
    }

    /**
     * Get the files its workspaces need that are not part of its code.
     *
     * @return HasMany<WorkspaceFile, $this>
     */
    public function workspaceFiles(): HasMany
    {
        return $this->hasMany(WorkspaceFile::class);
    }

    /**
     * Get the place the project is published to, safe to show: the remote
     * without its credentials.
     */
    public function publishTarget(): ?string
    {
        return $this->deploy_remote === null ? null : (string) preg_replace('#(://)[^/@\s]+@#', '$1', $this->deploy_remote);
    }

    /**
     * Get the times the project was published, or tried to be.
     *
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
