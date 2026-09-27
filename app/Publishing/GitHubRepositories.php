<?php

namespace App\Publishing;

use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Private repositories in our GitHub organization, where a managed host
 * deploys published apps from. A repository holds only the app's own code.
 */
class GitHubRepositories
{
    /**
     * Determine if an organization and a token are configured.
     */
    public function configured(): bool
    {
        return filled(config('builder.publishing.github.organization')) && filled(config('builder.publishing.github.token'));
    }

    /**
     * Create the project's private repository, or find the one made by an
     * earlier attempt, and get its full name ("organization/name").
     *
     * @throws RequestException
     */
    public function ensure(Project $project): string
    {
        $organization = (string) config('builder.publishing.github.organization');
        $name = Str::slug($project->name).'-'.$project->id;

        $response = $this->request()->post("/orgs/{$organization}/repos", [
            'name' => $name,
            'private' => true,
            'has_issues' => false,
            'has_wiki' => false,
            'has_projects' => false,
        ]);

        // An earlier attempt may have made it before failing.
        if ($response->status() === 422 && str_contains($response->body(), 'already exists')) {
            return "{$organization}/{$name}";
        }

        return (string) $response->throw()->json('full_name');
    }

    /**
     * Get the address to push a repository to. It holds the token, so it is
     * built when needed and never stored.
     */
    public function remote(string $repository): string
    {
        return sprintf('https://x-access-token:%s@%s/%s.git', config('builder.publishing.github.token'), config('builder.publishing.github.git_host'), $repository);
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl((string) config('builder.publishing.github.url'))
            ->withToken((string) config('builder.publishing.github.token'))
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->accept('application/vnd.github+json')
            ->timeout(30);
    }
}
