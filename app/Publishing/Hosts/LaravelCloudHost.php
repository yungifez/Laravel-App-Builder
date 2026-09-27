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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publishes to Laravel Cloud in our organization, so the owner gets an
 * address with one click and never sees an account. The app's code lives in
 * a private repository in our GitHub organization, which Cloud deploys from.
 */
class LaravelCloudHost implements PublishingHost
{
    protected const BRANCH = 'main';

    public function __construct(
        private ProjectRepository $repository,
        private GitHubRepositories $github,
    ) {}

    public function ready(Project $project): bool
    {
        return filled(config('builder.publishing.laravel_cloud.token')) && $this->github->configured();
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

            $this->repository->push($project, $deployment->commit_sha, $this->github->remote($state['repository']), self::BRANCH);

            if (! isset($state['environment'])) {
                $state = $this->createApplication($project, $state);
            }

            $release = $this->cloud()->post("/environments/{$state['environment']}/deployments")->throw();

            $deployment->update(['host_release_id' => (string) $release->json('data.id'), 'host_status' => (string) $release->json('data.attributes.status', 'pending')]);
        } catch (RepositoryConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Push errors come without the token (ProjectRepository::push),
            // and API errors never carry it, so operators can read this.
            report($exception);

            throw new PublishingFailed(__('Your hosting did not take the new version. Your app online has not changed.'), previous: $exception);
        }
    }

    public function progress(Deployment $deployment): ReleaseProgress
    {
        if ($deployment->host_release_id === null) {
            return ReleaseProgress::Unknown;
        }

        $status = (string) $this->cloud()->get("/deployments/{$deployment->host_release_id}")->throw()->json('data.attributes.status');

        $deployment->update(['host_status' => $status]);

        return match ($status) {
            'deployment.succeeded' => ReleaseProgress::Live,
            'build.failed', 'deployment.failed', 'failed', 'cancelled' => ReleaseProgress::Failed,
            default => ReleaseProgress::Pending,
        };
    }

    public function errors(Deployment $deployment, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $environment = $deployment->project->host_state['environment'] ?? null;

        if ($environment === null) {
            return null;
        }

        $errors = [];
        $cursor = null;

        // A few pages are enough to tell the owner something is wrong; an
        // app failing on every request does not need every entry counted.
        for ($page = 0; $page < (int) config('builder.publishing.errors.pages'); $page++) {
            $response = $this->cloud()->get("/environments/{$environment}/logs", array_filter([
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'type' => 'application',
                'cursor' => $cursor,
            ]))->throw();

            foreach ((array) $response->json('data') as $entry) {
                if (($entry['type'] ?? null) === 'exception' || ($entry['level'] ?? null) === 'error') {
                    $errors[] = [
                        'class' => isset($entry['data']['class']) ? (string) $entry['data']['class'] : null,
                        'message' => (string) ($entry['message'] ?? ''),
                        'at' => (string) ($entry['logged_at'] ?? $to->toIso8601String()),
                    ];
                }
            }

            $cursor = $response->json('meta.cursor');

            if (blank($cursor) || $response->json('data') === []) {
                break;
            }
        }

        return $errors;
    }

    /**
     * Create the Cloud application from the app's repository, give it a
     * database, and record its address. Deploys happen only when we start
     * them, after the checks pass, never on every push.
     *
     * @param  array<string, string>  $state
     * @return array<string, string>
     */
    protected function createApplication(Project $project, array $state): array
    {
        $application = $this->cloud()->post('/applications', [
            'repository' => $state['repository'],
            'name' => Str::slug($project->name).'-'.$project->id,
            'region' => (string) config('builder.publishing.laravel_cloud.region'),
        ])->throw();

        $state['application'] = (string) $application->json('data.id');
        $state['environment'] = (string) $application->json('data.relationships.defaultEnvironment.data.id');

        $settings = ['uses_push_to_deploy' => false];

        if (filled($cluster = config('builder.publishing.laravel_cloud.database_cluster'))) {
            $database = $this->cloud()->post("/databases/clusters/{$cluster}/databases", [
                'name' => 'app_'.$project->id,
            ])->throw();

            $state['database'] = (string) $database->json('data.id');
            $settings['database_schema_id'] = $state['database'];
        }

        $environment = $this->cloud()->patch("/environments/{$state['environment']}", $settings)->throw();
        $domain = (string) $environment->json('data.attributes.vanity_domain');

        $project->update([
            'host_state' => $state,
            'live_url' => $domain === '' ? null : Str::start($domain, 'https://'),
        ]);

        return $state;
    }

    protected function cloud(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('builder.publishing.laravel_cloud.url'), '/').'/api')
            ->withToken((string) config('builder.publishing.laravel_cloud.token'))
            ->accept('application/vnd.api+json')
            ->asJson()
            ->timeout((int) config('builder.publishing.laravel_cloud.timeout'));
    }
}
