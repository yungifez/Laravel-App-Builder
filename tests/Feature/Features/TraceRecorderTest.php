<?php

namespace Tests\Feature\Features;

use App\Features\AppTraces;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use TraceRecorder\Provider;

/**
 * The recorder that goes into the box image, loaded into this app the way
 * it is loaded into an owner's app: as a service provider.
 */
class TraceRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected string $directory;

    protected Closure $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/trace-'.getmypid());
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
     * Start the recorder in this app and return a reader of what it wrote.
     *
     * @return callable(): list<array<string, mixed>>
     */
    protected function record(): callable
    {
        putenv("TRACE_RECORDER_DIR={$this->directory}");
        $this->app->register(Provider::class);

        return fn () => AppTraces::parse(File::exists("{$this->directory}/trace.jsonl") ? File::get("{$this->directory}/trace.jsonl") : '');
    }

    public function test_a_request_is_recorded_with_what_it_asked_saved_and_sent_and_where()
    {
        $user = User::factory()->create();
        Route::post('/_recorded/{user}', function (User $user) {
            DB::transaction(function () use ($user) {
                $user->update(['name' => 'Renamed']);
                Mail::raw('Hello', fn ($message) => $message->to('owner@example.com'));
            });
            dispatch(fn () => null);

            return response()->noContent();
        })->middleware('web');
        $recorded = $this->record();

        $this->post("/_recorded/{$user->id}")->assertNoContent();

        [$request] = $recorded();
        $this->assertSame(self::class.'::test_a_request_is_recorded_with_what_it_asked_saved_and_sent_and_where', $request['test']);
        $this->assertSame(['POST', '/_recorded/{user}', 204, false], [$request['method'], $request['route'], $request['status'], $request['refused']]);

        // The transaction that wraps this test is not the request's: only its own counts.
        $kinds = array_map(fn (array $effect) => $effect['kind'].' '.$effect['open'], $request['effects']);
        $this->assertSame(['begin 1', 'query 1', 'mail 1', 'commit 0', 'job 0'], array_slice($kinds, -5));

        // Queries keep their placeholders, and each thing names the line of this file it came from.
        $update = collect($request['effects'])->firstWhere('sql', 'update "users" set "name" = ?, "updated_at" = ? where "id" = ?');
        $this->assertMatchesRegularExpression('#^tests/Feature/Features/TraceRecorderTest\.php:\d+$#', $update['at']);
        $this->assertStringNotContainsString('Renamed', File::get("{$this->directory}/trace.jsonl"));

        // The reader finds the mail sent before the saving was finished.
        $measured = AppTraces::measure($recorded(), null, ['POST /_recorded/{user}']);
        $this->assertSame(['sent_before_saved'], array_column($measured['findings'], 'kind'));
        $this->assertStringStartsWith('mail', $measured['findings'][0]['what']);
    }

    public function test_refusals_rollbacks_and_reads_that_save_are_told_apart()
    {
        Route::middleware('web')->group(function () {
            Route::get('/_recorded/read', fn () => tap(response()->noContent(), fn () => User::factory()->create()));
            Route::post('/_recorded/denied', function () {
                User::factory()->create();
                abort(403);
            });
            Route::post('/_recorded/invalid', fn (Request $request) => $request->validate(['name' => 'required']));
            Route::post('/_recorded/undone', function () {
                try {
                    DB::transaction(function () {
                        User::factory()->create();
                        abort(422);
                    });
                } finally {
                    User::query()->count();
                }
            });
        });
        $recorded = $this->record();

        $this->get('/_recorded/read')->assertNoContent();
        $this->post('/_recorded/denied')->assertForbidden();
        $this->post('/_recorded/invalid')->assertSessionHasErrors('name');
        $this->post('/_recorded/undone')->assertStatus(422);
        $this->get('/_recorded/missing')->assertNotFound();

        $requests = $recorded();
        $this->assertSame([false, true, true, true, true], array_column($requests, 'refused'));
        $this->assertSame([204, 403, 302, 422, 404], array_column($requests, 'status'));
        $this->assertNull($requests[4]['route']);

        $routes = ['GET /_recorded/read', 'POST /_recorded/denied', 'POST /_recorded/invalid', 'POST /_recorded/undone'];
        $this->assertSame([
            ['saved_on_read', 'GET /_recorded/read', 'insert users'],
            ['kept_after_refusal', 'POST /_recorded/denied', 'insert users'],
        ], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['what']], AppTraces::measure($requests, null, $routes)['findings']));
    }

    public function test_a_fake_that_hides_what_is_sent_is_named()
    {
        Route::post('/_recorded/quiet', fn () => response()->noContent());
        $recorded = $this->record();
        Notification::fake();

        $this->post('/_recorded/quiet')->assertNoContent();

        $this->assertSame(['notifications'], $recorded()[0]['blind']);
    }

    public function test_the_startup_file_adds_the_recorder_to_a_copy_of_the_package_list_and_leaves_the_app_alone()
    {
        $app = "{$this->directory}/app";
        File::ensureDirectoryExists("{$app}/bootstrap/cache");
        File::ensureDirectoryExists("{$app}/out");
        File::put("{$app}/bootstrap/cache/packages.php", "<?php return ['laravel/tinker' => ['providers' => ['Laravel\\\\Tinker\\\\TinkerServiceProvider']]];");
        $before = File::hash("{$app}/bootstrap/cache/packages.php");
        File::put("{$app}/probe.php", '<?php echo getenv("APP_PACKAGES_CACHE"), "|", getenv("APP_SERVICES_CACHE"), "|", (int) class_exists("TraceRecorder\\\\Middleware");');
        $run = fn (array $environment) => Process::path($app)->env($environment)->run([PHP_BINARY, '-d', 'auto_prepend_file='.resource_path('trace-recorder/prepend.php'), 'probe.php']);

        $recording = $run(['TRACE_RECORDER_DIR' => "{$app}/out"]);

        $this->assertSame("{$app}/out/packages.php|{$app}/out/services.php|1", $recording->output());
        $this->assertSame([
            'laravel/tinker' => ['providers' => ['Laravel\Tinker\TinkerServiceProvider']],
            'trace-recorder' => ['providers' => ['TraceRecorder\Provider']],
        ], require "{$app}/out/packages.php");
        $this->assertSame($before, File::hash("{$app}/bootstrap/cache/packages.php"));
        $this->assertSame(['packages.php'], array_map(fn ($file) => $file->getFilename(), File::files("{$app}/bootstrap/cache")));

        // Without a folder to record into, it does nothing at all.
        $this->assertSame('||0', $run(['TRACE_RECORDER_DIR' => ''])->output());
    }
}
