<?php

namespace App\Console\Commands;

use App\Actions\Context\RequestHealthCheck;
use App\Enums\HealthCheckScope;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('health:look-up-packages')]
#[Description('Look up known security problems in the packages of apps not checked for a while')]
class LookUpPackages extends Command
{
    /**
     * Look up the packages of each app whose last check is older than the
     * configured days. An app nobody changes never hears of a new advisory
     * otherwise. Only the lookups run: the full checks cost an install and
     * every test, so they stay on the owner's request. An app with no code
     * yet is left out.
     */
    public function handle(RequestHealthCheck $requestHealthCheck): int
    {
        $since = now()->subDays((int) config('builder.verification.security.lookup_days'));
        $started = 0;

        Project::query()
            ->whereDoesntHave('healthChecks', fn ($query) => $query->where('created_at', '>=', $since))
            ->lazyById()
            ->each(function (Project $project) use ($requestHealthCheck, &$started) {
                if ($requestHealthCheck->handle($project, HealthCheckScope::Packages) !== null) {
                    $started++;
                }
            });

        $this->components->info("Looked up the packages of {$started} apps.");

        return self::SUCCESS;
    }
}
