<?php

namespace Tests\Feature\Workspaces;

use App\Workspaces\Drivers\LocalDriver;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class LocalDriverTest extends TestCase
{
    protected string $root;

    protected LocalDriver $driver;

    protected string $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/workspaces-'.Str::lower(Str::random(8)));
        $this->driver = new LocalDriver(root: $this->root, envPassthrough: ['PATH']);
        $this->workspaceId = $this->driver->create(new WorkspaceSpec('workspace-local-test', 'host', 1, 256, 64));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_commands_run_in_the_workspace_with_a_scrubbed_environment()
    {
        putenv('DB_PASSWORD=super-secret');

        try {
            $result = $this->driver->exec($this->workspaceId, ['sh', '-c', 'pwd; echo "password=${DB_PASSWORD:-}"'], 10);
        } finally {
            putenv('DB_PASSWORD');
        }

        $this->assertTrue($result->successful());
        $this->assertStringEndsWith('workspace-local-test', explode("\n", $result->output)[0]);
        $this->assertStringContainsString('password=', $result->output);
        $this->assertStringNotContainsString('super-secret', $result->output);
    }

    public function test_commands_that_run_too_long_are_stopped()
    {
        $result = $this->driver->exec($this->workspaceId, ['sleep', '5'], 1);

        $this->assertTrue($result->timedOut);
        $this->assertLessThan(4000, $result->durationMs);
    }

    public function test_files_round_trip_and_cannot_escape_the_workspace()
    {
        $this->driver->writeFile($this->workspaceId, 'nested/file.txt', 'hello');

        $this->assertSame('hello', $this->driver->readFile($this->workspaceId, 'nested/file.txt'));

        $this->expectException(InvalidArgumentException::class);
        $this->driver->writeFile($this->workspaceId, '../escape.txt', 'nope');
    }

    public function test_copying_a_project_skips_dependencies_and_secrets()
    {
        $source = $this->root.'/source';
        File::ensureDirectoryExists("{$source}/app");
        File::ensureDirectoryExists("{$source}/vendor/pkg");
        File::put("{$source}/app/Model.php", '<?php');
        File::put("{$source}/vendor/pkg/file.php", '<?php');
        File::put("{$source}/.env", 'APP_KEY=secret');
        File::put("{$source}/.env.example", 'APP_KEY=');

        $this->driver->copyDirectory($this->workspaceId, $source);

        $copy = "{$this->root}/{$this->workspaceId}";
        $this->assertFileExists("{$copy}/app/Model.php");
        $this->assertFileExists("{$copy}/.env.example");
        $this->assertFileDoesNotExist("{$copy}/.env");
        $this->assertDirectoryDoesNotExist("{$copy}/vendor");
    }

    public function test_destroying_removes_the_directory()
    {
        $this->driver->destroy($this->workspaceId);

        $this->assertDirectoryDoesNotExist("{$this->root}/{$this->workspaceId}");
    }

    public function test_workspace_identifiers_cannot_contain_paths()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->driver->exec('../etc', ['ls'], 5);
    }
}
