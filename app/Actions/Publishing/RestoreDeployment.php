<?php

namespace App\Actions\Publishing;

use App\Enums\DeploymentStatus;
use App\Jobs\PublishDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreDeployment
{
    /**
     * Put an earlier version back online, when a newer one went wrong. Only
     * a version that came online and answered its checks can come back, so
     * it goes without checking again. The app here keeps its newer changes,
     * and the next publish puts them online again.
     *
     * Only code goes back. Information people saved stays as it is, and so
     * does how it is stored: undoing that could lose what they saved.
     *
     * @throws ValidationException when the version cannot go back online.
     */
    public function handle(Project $project, User $owner, Deployment $restores): Deployment
    {
        if (! $restores->project->is($project) || $restores->status !== DeploymentStatus::Published) {
            throw ValidationException::withMessages(['restore' => __('Only a version that was online can go back online.')]);
        }

        return DB::transaction(function () use ($project, $owner, $restores) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $online = self::online($project);

            if ($online === null || $online->status->active()) {
                throw ValidationException::withMessages(['restore' => __('Your app is already being published.')]);
            }

            if ($online->commit_sha === $restores->commit_sha) {
                throw ValidationException::withMessages(['restore' => __('This version is online now.')]);
            }

            $deployment = $project->deployments()->create([
                'user_id' => $owner->id,
                'commit_sha' => $restores->commit_sha,
                'restores_deployment_id' => $restores->id,
                'branch' => $online->branch,
                'host' => $online->host,
                'status' => DeploymentStatus::Checking,
            ]);

            $deployment->featureRequests()->attach($restores->featureRequests()->pluck('feature_requests.id')->all());

            PublishDeployment::dispatch($deployment)->afterCommit();

            return $deployment;
        });
    }

    /**
     * Get the newest publish that was sent, which is what is online now, or
     * one on its way.
     */
    public static function online(Project $project): ?Deployment
    {
        return $project->deployments()
            ->where(fn ($query) => $query
                ->whereNotNull('pushed_at')
                ->orWhereIn('status', [DeploymentStatus::Checking, DeploymentStatus::Pushing, DeploymentStatus::Confirming]))
            ->latest('id')
            ->first();
    }

    /**
     * Get the version the owner can go back to: the newest that came online
     * before what is online now and has other code. After going back, that
     * is a version from before the one put back.
     */
    public static function previous(Project $project): ?Deployment
    {
        $online = self::online($project);

        if ($online === null || $online->status->active()) {
            return null;
        }

        return $project->deployments()
            ->where('status', DeploymentStatus::Published)
            ->where('id', '<', $online->restores_deployment_id ?? $online->id)
            ->where('commit_sha', '!=', $online->commit_sha)
            ->latest('id')
            ->first();
    }
}
