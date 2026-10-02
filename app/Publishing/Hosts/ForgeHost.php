<?php

namespace App\Publishing\Hosts;

use App\Models\Deployment;
use App\Models\Project;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\Publishing\Contracts\PublishingHost;
use App\Publishing\Exceptions\PublishingFailed;
use App\Publishing\GitHubRepositories;
use App\Publishing\ReleaseProgress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Forge\ForgeManager;
use Laravel\Forge\Resources\Backup;
use Laravel\Forge\Resources\BackupConfiguration;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\Site;
use RuntimeException;
use Throwable;

/**
 * Publishes to Hetzner servers that Forge manages in our organization. Many
 * apps share a server; each gets its own site, database and on-forge.com
 * address, so the owner publishes with one click and never sees an account.
 * Forge deploys the app's private repository in our GitHub organization.
 */
class ForgeHost implements PublishingHost
{
    protected const BRANCH = 'main';

    // Site states in which Forge has finished setting the site up.
    protected const SITE_READY = ['installed', 'never-deployed', 'deployed', 'deploying', 'failed'];

    public function __construct(
        private ProjectRepository $repository,
        private GitHubRepositories $github,
    ) {}

    public function ready(Project $project): bool
    {
        return filled(config('services.forge.token'))
            && filled(config('builder.publishing.forge.organization'))
            && $this->github->configured();
    }

    public function branch(Project $project): string
    {
        return self::BRANCH;
    }

    public function release(Project $project, Deployment $deployment): void
    {
        try {
            $state = $project->host_state ?? [];

            if (! isset($state['repository'])) {
                $state['repository'] = $this->github->ensure($project);
                $project->update(['host_state' => $state]);
            }

            $this->repository->push($project, $deployment->released(), $this->github->remote($state['repository']), self::BRANCH);

            // A site whose database password is still kept did not get its
            // settings yet.
            if (! isset($state['site']) || isset($state['database_password'])) {
                $state = $this->createSite($project, $state);
            }

            // The keys for the app's outside services go with every release,
            // so a key the owner changed is live with the next one.
            $this->writeEnvironment($state, $project->serviceEnvironment());

            $release = $this->forge()->createDeployment($this->organization(), (int) $state['server'], (int) $state['site']);

            $deployment->update(['host_release_id' => (string) $release->id, 'host_status' => $release->status ?? 'pending']);
        } catch (RepositoryConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Push errors come without the token (ProjectRepository::push),
            // and Forge's errors never carry it, so operators can read this.
            report($exception);

            throw new PublishingFailed(__('Your hosting did not take the new version. Your app online has not changed.'), previous: $exception);
        }
    }

    public function backup(Project $project, Deployment $deployment): ?string
    {
        $state = $project->host_state ?? [];

        // An app that was never published keeps nothing to copy yet.
        if (! isset($state['server'], $state['database'])) {
            return null;
        }

        try {
            $forge = $this->forge();
            $organization = $this->organization();
            $server = (int) $state['server'];

            if (! isset($state['backups'])) {
                $state['backups'] = (string) $this->backupConfiguration($project, $server, (int) $state['database']);
                $project->update(['host_state' => $state]);
            }

            $configuration = (int) $state['backups'];
            $before = $this->backupIds($server, $configuration);

            $forge->createBackup($organization, $server, $configuration);

            $copy = $forge->retry((int) config('builder.publishing.forge.backup_wait_seconds'), function () use ($forge, $organization, $server, $configuration, $before) {
                foreach ($forge->backups($organization, $server, $configuration)->lazy() as $backup) {
                    if (! $backup instanceof Backup || in_array($backup->id, $before, true)) {
                        continue;
                    }

                    if (str_contains((string) $backup->status, 'fail')) {
                        throw new RuntimeException("Forge could not copy the database (backup {$backup->id}).");
                    }

                    return filled($backup->finishedAt) ? $backup : null;
                }

                return null;
            });
        } catch (Throwable $exception) {
            report($exception);

            throw new PublishingFailed(__('I could not save a copy of your app\'s information first, so I did not publish. Your app online has not changed.'), previous: $exception);
        }

        /** @var Backup $copy */
        // Forge needs both IDs to bring the copy back.
        return "{$configuration}/{$copy->id}";
    }

    public function progress(Deployment $deployment): ReleaseProgress
    {
        $state = $deployment->project->host_state ?? [];

        if ($deployment->host_release_id === null || ! isset($state['server'], $state['site'])) {
            return ReleaseProgress::Unknown;
        }

        $status = (string) $this->forge()->deployment($this->organization(), (int) $state['server'], (int) $state['site'], (int) $deployment->host_release_id)->status;

        $deployment->update(['host_status' => $status]);

        return match ($status) {
            'finished' => ReleaseProgress::Live,
            'failed', 'failed-build', 'cancelled' => ReleaseProgress::Failed,
            default => ReleaseProgress::Pending,
        };
    }

    public function errors(Deployment $deployment, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $state = $deployment->project->host_state ?? [];

        if (! isset($state['server'], $state['site'])) {
            return null;
        }

        // Forge gives the end of the app's own log file, in Laravel's
        // format: "[time] env.LEVEL: message {context}".
        $log = $this->forge()->siteApplicationLog($this->organization(), (int) $state['server'], (int) $state['site']);
        preg_match_all('/^\[([0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9:.]+(?:[+-][0-9]{2}:?[0-9]{2})?)\] [\w-]+\.(?:ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $log, $entries, PREG_SET_ORDER);

        $errors = [];

        foreach ($entries as [, $time, $line]) {
            $at = CarbonImmutable::parse($time, 'UTC');

            if ($at->lessThan($from) || $at->greaterThan($to)) {
                continue;
            }

            $errors[] = [
                'class' => preg_match('/"exception":"\[object\] \(([\w\\\\]+)\(code: /', $line, $class) === 1 ? str_replace('\\\\', '\\', $class[1]) : null,
                'message' => trim((string) preg_replace('/ (\{".*|\[\])$/', '', $line)),
                'at' => $at->toIso8601String(),
            ];
        }

        return $errors;
    }

    public function spend(): ?array
    {
        return null;
    }

    /**
     * Give the app a site and a database on a server with room, and record
     * its address. Each step is saved as it is made, so a publish that
     * stops halfway picks up where it stopped instead of making them twice.
     *
     * @param  array<string, string>  $state
     * @return array<string, string>
     */
    protected function createSite(Project $project, array $state): array
    {
        $forge = $this->forge();
        $organization = $this->organization();

        if (! isset($state['server'])) {
            $state['server'] = (string) $this->serverWithRoom()->id;
            $project->update(['host_state' => $state]);
        }

        $server = (int) $state['server'];

        if (! isset($state['database'])) {
            $password = Str::password(40, symbols: false);

            // The password is kept, locked, only until it is in the app's
            // settings below.
            $state['database_password'] = Crypt::encryptString($password);
            $project->update(['host_state' => $state]);

            $database = $forge->setTimeout($this->wait())->createDatabase($organization, $server, [
                'name' => "app_{$project->id}",
                'user' => "app_{$project->id}",
                'password' => $password,
            ]);

            $state['database'] = (string) $database->id;
            $project->update(['host_state' => $state]);
        }

        if (! isset($state['site'])) {
            $state['site'] = (string) $forge->createSite($organization, $server, [
                'type' => 'laravel',
                'domain_mode' => 'on-forge',
                'name' => Str::limit(Str::slug($project->name), 40, '').'-'.$project->id,
                'php_version' => (string) config('builder.publishing.forge.php_version'),
                'source_control_provider' => 'github',
                'repository' => $state['repository'],
                'branch' => self::BRANCH,
                'database_id' => (int) $state['database'],
                'install_composer_dependencies' => true,
                'zero_downtime_deployments' => true,
                'push_to_deploy' => false,
            ])->id;
            $project->update(['host_state' => $state]);
        }

        $url = Str::start((string) $this->waitForSite((int) $state['site'])->url, 'https://');

        $this->writeEnvironment($state, [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            // Forge reads errors from this one file (see errors()).
            'LOG_STACK' => 'single',
            'DB_CONNECTION' => $this->databaseDriver(),
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => "app_{$project->id}",
            'DB_USERNAME' => "app_{$project->id}",
            'DB_PASSWORD' => Crypt::decryptString($state['database_password']),
        ]);

        unset($state['database_password']);

        $project->update(['host_state' => $state, 'live_url' => $url]);

        return $state;
    }

    /**
     * Get one of our servers with room for another app, making a new one
     * when all are full. Only one publish looks at a time, so two first
     * publishes do not both make a server.
     */
    protected function serverWithRoom(): Server
    {
        return Cache::lock('builder:forge-server', $this->serverWait() + 60)->block($this->serverWait() + 60, function () {
            $forge = $this->forge();
            $organization = $this->organization();
            $prefix = (string) config('builder.publishing.forge.server_prefix').'-';

            foreach ($forge->servers($organization)->lazy() as $server) {
                if (! $server instanceof Server || ! str_starts_with((string) $server->name, $prefix) || ! $server->isReady || $server->revoked) {
                    continue;
                }

                $sites = iterator_count($forge->serverSites($organization, (int) $server->id)->lazy());

                if ($sites < (int) config('builder.publishing.forge.sites_per_server')) {
                    return $server;
                }
            }

            /** @var array{credential: mixed, network: mixed, region: mixed, size: mixed} $hetzner */
            $hetzner = Arr::only((array) config('builder.publishing.forge'), ['credential', 'network', 'region', 'size']);

            if (count(array_filter($hetzner, filled(...))) < 4) {
                throw new RuntimeException('Every Forge server for apps is full, and the Hetzner IDs to make another are not set (FORGE_HETZNER_*).');
            }

            // The SDK's own wait passes Forge's text ID where it needs a
            // number, so the wait for the server happens here.
            $server = $forge->createServer($organization, [
                'name' => $prefix.Str::lower(Str::random(8)),
                'provider' => 'hetzner',
                'credential_id' => (int) $hetzner['credential'],
                'type' => 'app',
                'ubuntu_version' => '24.04',
                'php_version' => (string) config('builder.publishing.forge.php_version'),
                'database_type' => (string) config('builder.publishing.forge.database_type'),
                'hetzner' => [
                    'region_id' => (string) $hetzner['region'],
                    'size_id' => (string) $hetzner['size'],
                    'network_id' => (int) $hetzner['network'],
                ],
            ], false);

            return $forge->retry($this->serverWait(), function () use ($forge, $organization, $server) {
                $current = $forge->server($organization, (int) $server->id);

                return $current->isReady ? $current : null;
            });
        });
    }

    /**
     * Make the app's backup settings: a copy each night, kept for a week,
     * in our storage. Forge does not answer with the new settings' ID, so
     * they are found by name.
     */
    protected function backupConfiguration(Project $project, int $server, int $database): int
    {
        $storage = config('builder.publishing.forge.backup_storage');

        if (blank($storage)) {
            throw new RuntimeException('There is no storage for copies of apps\' databases (FORGE_BACKUP_STORAGE).');
        }

        $forge = $this->forge();
        $organization = $this->organization();
        $name = "app-{$project->id}";

        $forge->createBackupConfiguration($organization, $server, [
            'storage_provider_id' => (int) $storage,
            'name' => $name,
            'directory' => $name,
            'frequency' => 'daily',
            'time' => '03:00',
            'database_ids' => [$database],
            'retention' => (int) config('builder.publishing.forge.backup_retention'),
        ]);

        foreach ($forge->backupConfigurations($organization, $server)->lazy() as $configuration) {
            if ($configuration instanceof BackupConfiguration && $configuration->name === $name) {
                return (int) $configuration->id;
            }
        }

        throw new RuntimeException("Forge did not keep the backup settings {$name}.");
    }

    /**
     * Get the IDs of the copies Forge already has, so a new one can be told
     * apart from them.
     *
     * @return list<int|null>
     */
    protected function backupIds(int $server, int $configuration): array
    {
        $ids = [];

        foreach ($this->forge()->backups($this->organization(), $server, $configuration)->lazy() as $backup) {
            $ids[] = $backup instanceof Backup ? $backup->id : null;
        }

        return $ids;
    }

    /**
     * Wait until Forge has set the site up, so its settings can be written.
     */
    protected function waitForSite(int $site): Site
    {
        $forge = $this->forge();

        return $forge->retry($this->wait(), function () use ($forge, $site) {
            $current = $forge->organizationSite($this->organization(), $site);

            return in_array($current->status, self::SITE_READY, true) ? $current : null;
        });
    }

    /**
     * Set values in the site's settings file, keeping every other line.
     *
     * @param  array<string, string>  $state
     * @param  array<string, string>  $values
     */
    protected function writeEnvironment(array $state, array $values): void
    {
        if ($values === []) {
            return;
        }

        $forge = $this->forge();
        $organization = $this->organization();
        $content = $forge->siteEnvironment($organization, (int) $state['server'], (int) $state['site']);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->environmentValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $content = preg_match($pattern, $content)
                ? (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content, 1)
                : rtrim($content, "\n").($content === '' ? '' : "\n").$line."\n";
        }

        $forge->updateSiteEnvironment($organization, (int) $state['server'], (int) $state['site'], $content);
    }

    /**
     * Quote a value when the .env reader would otherwise cut it short.
     */
    protected function environmentValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /**
     * Get Laravel's database driver for the servers' database.
     */
    protected function databaseDriver(): string
    {
        $type = (string) config('builder.publishing.forge.database_type');

        return match (true) {
            str_starts_with($type, 'postgres') => 'pgsql',
            str_starts_with($type, 'mariadb') => 'mariadb',
            default => 'mysql',
        };
    }

    protected function organization(): string
    {
        return (string) config('builder.publishing.forge.organization');
    }

    protected function wait(): int
    {
        return (int) config('builder.publishing.forge.wait_seconds');
    }

    protected function serverWait(): int
    {
        return (int) config('builder.publishing.forge.server_wait_seconds');
    }

    protected function forge(): ForgeManager
    {
        return app(ForgeManager::class);
    }
}
