<?php

namespace App\Evaluation;

use App\Workspaces\CommandResult;
use App\Workspaces\Drivers\LocalDriver;
use App\Workspaces\WorkspaceSpec;
use RuntimeException;

/**
 * A scratch copy of the project for the plain agent and the scorer: the
 * project's files, its installed dependencies, an app key, and a git
 * baseline that changes are measured against.
 *
 * Commands run through the local workspace driver, with its scrubbed
 * environment.
 */
class Workbench
{
    /**
     * Git identity for the baseline commit, so no host configuration is needed.
     *
     * @var list<string>
     */
    protected const GIT_IDENTITY = ['-c', 'user.name=Evaluation', '-c', 'user.email=evaluation@localhost', '-c', 'commit.gpgsign=false'];

    protected function __construct(
        protected LocalDriver $driver,
        public readonly string $name,
        public readonly string $path,
    ) {}

    /**
     * Make a fresh workbench with the given name, replacing any earlier one.
     *
     * @throws RuntimeException when the project cannot be prepared.
     */
    public static function create(string $name): self
    {
        $root = (string) config('evaluation.workspaces');

        /** @var list<string> $passthrough */
        $passthrough = config('workspaces.drivers.local.env_passthrough', []);

        $driver = new LocalDriver(root: $root, envPassthrough: $passthrough);
        $driver->destroy($name);
        $driver->create(new WorkspaceSpec($name, 'host', 1, 1024, 512));

        $workbench = new self($driver, $name, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name);

        $driver->copyDirectory($name, Suite::resolve((string) config('evaluation.project')));

        foreach (self::setupCommands() as [$label, $command]) {
            $workbench->mustRun($command, $label);
        }

        $workbench->mustRun(['git', 'init', '--quiet'], 'git init');
        $workbench->mustRun(['git', 'add', '--all'], 'git add');
        $workbench->mustRun(['git', ...self::GIT_IDENTITY, 'commit', '--quiet', '--no-verify', '-m', 'Baseline'], 'baseline commit');

        return $workbench;
    }

    /**
     * Get the commands that make a copied project runnable without
     * downloading anything: link the installed dependencies, create the
     * environment file and key, and generate the route helpers.
     *
     * Dependencies are hard-linked, not copied, so each workspace costs no
     * extra disk. The dependency directory must be on the same filesystem as
     * the workspaces, and nothing may edit dependency files in place; check
     * it with a fingerprint before and after a run.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function setupCommands(): array
    {
        $dependencies = Suite::resolve((string) config('evaluation.dependencies'));

        return [
            ['Link PHP dependencies', ['cp', '-al', "{$dependencies}/vendor", 'vendor']],
            ['Link Node dependencies', ['cp', '-al', "{$dependencies}/node_modules", 'node_modules']],
            ['Create .env', ['cp', '.env.example', '.env']],
            ['Generate app key', ['php', 'artisan', 'key:generate', '--no-interaction']],
            ['Generate route helpers', ['php', 'artisan', 'wayfinder:generate', '--with-form']],
        ];
    }

    /**
     * Get the setup commands in the shape config/builder.php uses.
     *
     * @return list<array{name: string, command: list<string>, timeout: int}>
     */
    public static function setupSteps(): array
    {
        return array_map(fn (array $step) => ['name' => $step[0], 'command' => $step[1], 'timeout' => 600], self::setupCommands());
    }

    /**
     * Run a command in the workbench.
     *
     * @param  list<string>  $command
     */
    public function run(array $command, int $timeoutSeconds = 600): CommandResult
    {
        return $this->driver->exec($this->name, $command, $timeoutSeconds);
    }

    /**
     * Apply a patch and report whether it applied. The route helpers are
     * generated again afterwards, as verification does after applying a
     * change, so new routes have their TypeScript helpers.
     */
    public function apply(string $patch, string $label = 'change'): CommandResult
    {
        $file = '.git/evaluation-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($label)).'.patch';
        $this->driver->writeFile($this->name, $file, $patch);

        $result = $this->run(['git', 'apply', '--whitespace=nowarn', $file], 120);

        if ($result->successful()) {
            $this->run(['php', 'artisan', 'wayfinder:generate', '--with-form'], 120);
        }

        return $result;
    }

    /**
     * Get every change since the baseline, new files included.
     */
    public function diff(): string
    {
        $this->mustRun(['git', 'add', '--all'], 'git add');

        return $this->mustRun(['git', 'diff', '--cached', '--binary', 'HEAD'], 'git diff')->output;
    }

    /**
     * Write a file into the workbench.
     */
    public function write(string $path, string $contents): void
    {
        $this->driver->writeFile($this->name, $path, $contents);
    }

    /**
     * Remove the workbench.
     */
    public function destroy(): void
    {
        $this->driver->destroy($this->name);
    }

    /**
     * Run a command that must succeed.
     *
     * @param  list<string>  $command
     *
     * @throws RuntimeException
     */
    protected function mustRun(array $command, string $label): CommandResult
    {
        $result = $this->run($command);

        if (! $result->successful()) {
            throw new RuntimeException("Workbench [{$this->name}]: {$label} failed: ".trim($result->errorOutput ?: $result->output));
        }

        return $result;
    }
}
