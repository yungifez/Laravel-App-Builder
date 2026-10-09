<?php

namespace Tests\Feature\Runs;

use App\Actions\Workspaces\DescribeEnvironment;
use App\Models\Workspace;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class RunEnvironmentTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    public function test_a_prepared_run_records_the_tool_versions_and_lockfiles_it_builds_with()
    {
        $source = $this->makeProjectSource(['composer.lock' => '{"packages": []}', 'package-lock.json' => '{"lockfileVersion": 3}']);

        [$run] = $this->implementingRun($source);

        $environment = $run->environment;
        $this->assertNotNull($environment);
        $this->assertSame('host', $environment['image']);
        // Outside a box there is no image to name.
        $this->assertNull($environment['image_digest']);
        $this->assertSame(PHP_VERSION, $environment['tools']['php']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $environment['tools']['composer']);
        $this->assertSame([
            'composer.lock' => hash_file('sha256', "{$source}/composer.lock"),
            'package-lock.json' => hash_file('sha256', "{$source}/package-lock.json"),
        ], $environment['lockfiles']);
    }

    public function test_a_box_records_the_image_it_started_from()
    {
        $this->fakeWorkspaces()->onExec = fn () => new CommandResult(exitCode: 0, output: implode("\n", [
            'image_digest registry.example.test/builder-box@sha256:'.str_repeat('a', 64),
            'php 8.5.1',
            'composer Composer version 2.9.2 2026-09-01 10:00:00',
            'node v24.3.0',
            'npm 11.4.2',
            'postgres psql (PostgreSQL) 17.2 (Ubuntu 17.2-1)',
            'lockfile pnpm-lock.yaml '.str_repeat('b', 64),
        ]), errorOutput: '', durationMs: 5);
        $workspace = Workspace::factory()->create(['image' => 'box']);

        $this->assertSame([
            'image' => 'box',
            'image_digest' => 'registry.example.test/builder-box@sha256:'.str_repeat('a', 64),
            'tools' => ['php' => '8.5.1', 'composer' => '2.9.2', 'node' => '24.3.0', 'npm' => '11.4.2', 'postgres' => '17.2'],
            'lockfiles' => ['pnpm-lock.yaml' => str_repeat('b', 64)],
        ], app(DescribeEnvironment::class)->handle($workspace));
    }

    public function test_what_a_box_cannot_say_or_says_wrongly_is_left_empty()
    {
        $driver = $this->fakeWorkspaces();
        $driver->onExec = fn () => new CommandResult(exitCode: 0, output: implode("\n", [
            'image_digest builder-box; rm -rf /',
            'php ',
            'node not found',
            'lockfile composer.lock not-a-hash',
            'lockfile ../../etc/passwd '.str_repeat('c', 64),
        ]), errorOutput: '', durationMs: 5);
        $workspace = Workspace::factory()->create();

        $this->assertSame([
            'image' => 'php:8.4-cli',
            'image_digest' => null,
            'tools' => ['php' => null, 'composer' => null, 'node' => null, 'npm' => null, 'postgres' => null],
            'lockfiles' => [],
        ], app(DescribeEnvironment::class)->handle($workspace));

        // A command no runner answered leaves everything empty and stops nothing.
        $driver->onExec = fn () => new CommandResult(exitCode: -1, output: '', errorOutput: '', durationMs: 0, lost: true);

        $this->assertSame([null, []], array_values(array_intersect_key(app(DescribeEnvironment::class)->handle($workspace), array_flip(['image_digest', 'lockfiles']))));
    }
}
