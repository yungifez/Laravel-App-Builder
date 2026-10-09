<?php

namespace App\Workspaces\Boxes\Providers;

use App\Workspaces\Boxes\Contracts\BoxProvider;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * One container per workspace, made through a small box service that holds
 * the Docker socket (docker/boxes). It simulates a hosting provider's boxes
 * in local development: each box has its own runner, its own token and its
 * own resource ceilings. Containers share the host's kernel, so this is for
 * our own test apps, not for untrusted code.
 */
class DockerProvider implements BoxProvider
{
    public function __construct(
        protected string $url,
        protected string $token,
        protected string $controlPlaneUrl,
        protected string $key,
    ) {}

    public function create(WorkspaceSpec $spec): string
    {
        Http::withToken($this->token)
            ->timeout(60)
            ->post("{$this->url}/boxes", [
                'name' => $this->name($spec->name),
                'cpus' => $spec->cpus,
                'memory_mb' => $spec->memoryMb,
                'pids' => $spec->pids,
                'env' => [
                    'RUNNER_URL' => $this->controlPlaneUrl,
                    'RUNNER_TOKEN' => $this->tokenFor($spec->name),
                ],
            ])
            ->throw();

        return $spec->name;
    }

    /**
     * Each box has a runner of its own, named after the box.
     */
    public function runnerFor(string $box): string
    {
        return $box;
    }

    /**
     * A token is the runner's name and a signature of it, so each box's
     * token opens only that box's commands, with nothing stored.
     */
    public function authenticate(string $token): ?string
    {
        [$runner, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! preg_match('/^[a-z0-9-]{1,60}$/', $runner) || $signature === '') {
            return null;
        }

        return hash_equals($this->signature($runner), $signature) ? $runner : null;
    }

    public function serviceUrl(string $box, int $port): string
    {
        return "http://box-{$this->name($box)}:{$port}";
    }

    public function destroy(string $box): void
    {
        Http::withToken($this->token)->timeout(60)->delete("{$this->url}/boxes/{$this->name($box)}")->throw();
    }

    /**
     * Get the token a box's runner signs in with.
     */
    public function tokenFor(string $runner): string
    {
        return "{$runner}.{$this->signature($runner)}";
    }

    protected function signature(string $runner): string
    {
        return hash_hmac('sha256', "box-runner:{$runner}", $this->key);
    }

    /**
     * Refuse names Docker or the box service would not take.
     */
    protected function name(string $box): string
    {
        if (! preg_match('/^[a-z0-9-]{1,60}$/', $box)) {
            throw new InvalidArgumentException("Invalid box name [{$box}].");
        }

        return $box;
    }
}
