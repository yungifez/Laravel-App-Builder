<?php

namespace App\Actions\Context;

use App\Enums\HealthCheckScope;
use App\Enums\HealthCheckStatus;
use App\Models\HealthCheck;
use App\Models\Project;
use App\Projects\ProjectRepository;

/**
 * Get where the app's current version stands: the owner's last full check,
 * with what a newer scheduled package lookup found in place of that check's
 * own lookups. A lookup alone never hides what the full check found, and
 * a check of an earlier version is out of date.
 */
class ReadAppHealth
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Get the check to show, and the newest check it is made of, which a
     * fix is asked for by. Null when nothing current was checked.
     *
     * @return array{check: HealthCheck, id: int}|null
     */
    public function handle(Project $project): ?array
    {
        $head = $this->repository->head($project);
        $full = $project->healthChecks()->where('scope', HealthCheckScope::Full)->latest('id')->first();

        if ($full !== null && ! $full->status->active() && $full->commit_sha !== $head) {
            $full = null;
        }

        if ($full?->status->active()) {
            return ['check' => $full, 'id' => $full->id];
        }

        $lookup = $project->healthChecks()
            ->where('scope', HealthCheckScope::Packages)
            ->where('commit_sha', $head)
            ->whereIn('status', [HealthCheckStatus::Passed, HealthCheckStatus::Failed])
            ->when($full, fn ($query, HealthCheck $full) => $query->where('id', '>', $full->id))
            ->latest('id')
            ->first();

        if ($lookup === null) {
            return $full === null ? null : ['check' => $full, 'id' => $full->id];
        }

        // Nothing to say about lookups alone that found nothing.
        if ($full === null) {
            return $lookup->status === HealthCheckStatus::Failed ? ['check' => $lookup, 'id' => $lookup->id] : null;
        }

        if ($full->status === HealthCheckStatus::Errored) {
            return ['check' => $full, 'id' => $full->id];
        }

        // Each lookup the scheduler read takes the place of the full
        // check's lookup of the same name.
        $newer = array_column($lookup->results ?? [], null, 'name');
        $results = [
            ...array_values(array_filter($full->results ?? [], fn (array $result) => ! isset($newer[$result['name']]))),
            ...array_values($newer),
        ];
        $passed = array_filter($results, fn (array $result) => ! $result['passed']) === [];

        $check = $full->replicate()->forceFill([
            'results' => $results,
            'status' => $passed ? HealthCheckStatus::Passed : HealthCheckStatus::Failed,
        ]);

        return ['check' => $check, 'id' => $lookup->id];
    }
}
