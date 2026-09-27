<?php

namespace App\Actions\Operations;

use App\Models\Project;
use App\Publishing\PublishingHostManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * What hosting the owners' apps costs us this billing period. We pay for
 * the apps we host (owners are not charged yet), so operators need to see
 * which apps cost what. Each host's own figures are used, never estimates.
 */
class SummarizeHostingSpend
{
    public function __construct(private PublishingHostManager $hosts) {}

    /**
     * Get the hosting spend per host, with each app matched to its project
     * where we know it. A host that did not answer is listed as unknown.
     *
     * @return list<array{host: string, currency: string|null, total_cents: int|null, apps: list<array{project_id: int|null, name: string, cents: int}>, error: bool}>
     */
    public function handle(): array
    {
        $spend = [];

        foreach ((array) config('builder.publishing.spend_hosts') as $host) {
            try {
                // Hosts update their figures a few times an hour at most.
                $figures = Cache::remember("hosting-spend:{$host}", now()->addMinutes((int) config('builder.publishing.spend_cache_minutes')), fn () => $this->hosts->driver($host)->spend());
            } catch (Throwable $exception) {
                report($exception);
                $spend[] = ['host' => $host, 'currency' => null, 'total_cents' => null, 'apps' => [], 'error' => true];

                continue;
            }

            if ($figures === null) {
                continue;
            }

            $spend[] = [
                'host' => $host,
                'currency' => $figures['currency'],
                'total_cents' => $figures['total_cents'],
                'apps' => $this->apps($host, $figures['applications']),
                'error' => false,
            ];
        }

        return $spend;
    }

    /**
     * Match the host's apps to our projects, costliest first.
     *
     * @param  list<array{application: string, cents: int}>  $applications
     * @return list<array{project_id: int|null, name: string, cents: int}>
     */
    protected function apps(string $host, array $applications): array
    {
        $projects = [];

        // A host may name an app by its ID or by the name we gave it.
        Project::query()->where('host', $host)->whereNotNull('host_state')->get()
            ->each(function (Project $project) use (&$projects) {
                $projects[(string) ($project->host_state['application'] ?? '')] = $project;
                $projects[Str::slug($project->name).'-'.$project->id] = $project;
            });

        $apps = array_map(function (array $application) use ($projects) {
            $project = $projects[$application['application']] ?? null;

            return [
                'project_id' => $project?->id,
                'name' => $project === null ? $application['application'] : $project->name,
                'cents' => $application['cents'],
            ];
        }, $applications);

        usort($apps, fn (array $a, array $b) => $b['cents'] <=> $a['cents']);

        return $apps;
    }
}
