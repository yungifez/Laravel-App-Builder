<?php

namespace App\Actions\Context;

use App\Enums\HealthCheckStatus;
use App\Jobs\CheckProjectHealth;
use App\Models\HealthCheck;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;

class RequestHealthCheck
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Run the full checks on the app's current version. A check already
     * under way is the answer, so a second click does not start another.
     * An app with no code yet has nothing to check.
     */
    public function handle(Project $project): ?HealthCheck
    {
        if (! $this->repository->exists($project)) {
            return null;
        }

        $head = $this->repository->head($project);

        if ($head === '') {
            return null;
        }

        return DB::transaction(function () use ($project, $head) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $active = $project->healthChecks()
                ->whereIn('status', [HealthCheckStatus::Queued, HealthCheckStatus::Running])
                ->latest('id')
                ->first();

            if ($active !== null) {
                return $active;
            }

            $healthCheck = $project->healthChecks()->create([
                'commit_sha' => $head,
                'status' => HealthCheckStatus::Queued,
            ]);

            CheckProjectHealth::dispatch($healthCheck)->afterCommit();

            return $healthCheck;
        });
    }
}
