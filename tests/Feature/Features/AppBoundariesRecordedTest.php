<?php

namespace Tests\Feature\Features;

use App\Features\AppBoundaries;
use App\Features\AppTraces;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\BoundaryApp;
use Tests\TestCase;
use TraceRecorder\Provider;

/**
 * The boundary rules on what the recorder in the box image really writes,
 * for an app that saves while Laravel checks who may act, checks the
 * input, handles the request and builds the answer.
 */
class AppBoundariesRecordedTest extends TestCase
{
    use RefreshDatabase;

    protected string $directory;

    protected Closure $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/boundaries-'.getmypid());
        File::ensureDirectoryExists($this->directory);

        spl_autoload_register($this->loader = function (string $class): void {
            if (str_starts_with($class, 'TraceRecorder\\')) {
                require_once resource_path('trace-recorder/src/'.substr($class, 14).'.php');
            }
        });
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->loader);
        putenv('TRACE_RECORDER_DIR');
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * A patch that adds the whole fixture app, as a change that wrote it would.
     */
    protected function addingTheApp(): string
    {
        $lines = explode("\n", rtrim(File::get(base_path(BoundaryApp::PATH)), "\n"));

        return implode("\n", [
            'diff --git a/'.BoundaryApp::PATH.' b/'.BoundaryApp::PATH,
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/'.BoundaryApp::PATH,
            '@@ -0,0 +1,'.count($lines).' @@',
            ...array_map(fn (string $line) => '+'.$line, $lines),
        ])."\n";
    }

    public function test_saves_while_checking_and_building_the_answer_are_found_and_the_handlers_save_is_not()
    {
        $user = User::factory()->create(['name' => 'Ada']);
        Route::post('/_boundaries/show', [BoundaryApp::class, 'show'])->middleware('web');
        Gate::define('counted', [BoundaryApp::class, 'counted']);

        putenv("TRACE_RECORDER_DIR={$this->directory}");
        $this->app->register(Provider::class);

        $this->actingAs($user)->post('/_boundaries/show', ['name' => 'Ada'])->assertOk();

        $requests = AppTraces::parse(File::get("{$this->directory}/trace.jsonl"));
        $measured = AppBoundaries::measure($requests, $this->addingTheApp());

        $this->assertSame([
            AppBoundaries::CHANGED_WHILE_AUTHORIZING,
            AppBoundaries::CHANGED_WHILE_VALIDATING,
            AppBoundaries::CHANGED_WHILE_RENDERING,
        ], array_column($measured['findings'], 'kind'));
        $this->assertSame(['update users'], array_values(array_unique(array_column($measured['findings'], 'what'))));
        $this->assertSame(BoundaryApp::class.'::counted', $measured['findings'][0]['in']);
        $this->assertStringStartsWith(BoundaryApp::PATH.':', (string) $measured['findings'][2]['at']);

        // Code the change did not add is the app's own history, not the change's.
        $before = AppBoundaries::measure($requests, null);
        $this->assertSame([], $before['findings']);
        $this->assertSame(3, $before['existing']);
    }
}
