<?php

namespace Tests\Feature\Features;

use App\Features\AppFaults;
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
use RuntimeException;
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
        putenv('TRACE_RECORDER_FAULT');
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

    /**
     * Start the recorder with one thing of this test's second request made
     * to fail. The first request shows where that thing is.
     *
     * @return callable(): list<array<string, mixed>>
     */
    protected function recordWithFailure(int $effect, string $kind): callable
    {
        putenv('TRACE_RECORDER_FAULT='.json_encode(['test' => self::class.'::'.$this->name(), 'request' => 1, 'effect' => $effect, 'kind' => $kind]));

        return $this->record();
    }

    /**
     * A patch that adds every line of this file, so what its routes do is
     * the change's.
     */
    protected function wholeFilePatch(): string
    {
        $path = 'tests/Feature/Features/TraceRecorderTest.php';
        $lines = count(file(__FILE__) ?: []);

        return "diff --git a/{$path} b/{$path}\n--- /dev/null\n+++ b/{$path}\n@@ -0,0 +1,{$lines} @@\n".str_repeat("+//\n", $lines);
    }

    /**
     * Measure the place found in the normal request against the request
     * after it, where its failure was caused.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    protected function measureFailure(array $requests, string $failed): array
    {
        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $position = array_search($failed, array_column($points, 'failed'), true);
        $this->assertNotFalse($position);
        $this->assertSame($requests[1]['fault'], $points[$position]['fault']['effect']);
        $points[$position]['fault']['request'] = 1;

        return (array) AppFaults::measure($points, [$position => $requests], $this->wholeFilePatch());
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

    public function test_an_email_made_to_fail_ends_the_request_in_an_error_and_what_it_saved_stays()
    {
        Route::post('/_failing/order', function () {
            DB::table('users')->where('id', 0)->update(['name' => 'Ordered']);
            Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));

            return response()->noContent();
        });
        $recorded = $this->recordWithFailure(effect: 1, kind: 'mail');

        $this->post('/_failing/order')->assertNoContent();
        $this->post('/_failing/order')->assertStatus(500);
        // One failure only: the request after it runs as usual.
        $this->post('/_failing/order')->assertNoContent();

        $requests = $recorded();
        $this->assertSame([0, 1, 2], array_column($requests, 'n'));
        $this->assertSame([204, 500, 204], array_column($requests, 'status'));
        $this->assertSame([false, true, false], array_map(fn (array $request) => isset($request['fault']), $requests));
        $this->assertSame(['query', 'mail'], array_column($requests[1]['effects'], 'kind'));

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['saved_then_failed', 'POST /_failing/order', 'mail message', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_save_made_to_fail_is_put_back_but_what_was_sent_before_it_is_gone()
    {
        Route::post('/_failing/invite', function () {
            Mail::raw('You are invited', fn ($message) => $message->to('guest@example.com'));
            DB::transaction(fn () => User::factory()->create());

            return response()->noContent();
        });
        $recorded = $this->recordWithFailure(effect: 2, kind: 'query');

        $this->post('/_failing/invite')->assertNoContent();
        $this->post('/_failing/invite')->assertStatus(500);

        $requests = $recorded();
        $this->assertSame(['mail 0', 'begin 1', 'query 1', 'commit 0'], array_map(fn (array $effect) => $effect['kind'].' '.$effect['open'], $requests[0]['effects']));
        $this->assertSame(['mail 0', 'begin 1', 'query 1', 'rollback 0'], array_map(fn (array $effect) => $effect['kind'].' '.$effect['open'], $requests[1]['effects']));
        $this->assertSame(2, $requests[1]['fault']);
        // The save that failed was put back; the first request's stayed.
        $this->assertSame(1, User::query()->count());

        $measured = $this->measureFailure($requests, 'insert users');
        $this->assertSame([['sent_then_lost', 'insert users', 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_nothing_fails_for_another_test_or_another_kind_of_thing()
    {
        Route::post('/_failing/order', function () {
            Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));

            return response()->noContent();
        });
        putenv('TRACE_RECORDER_FAULT='.json_encode(['test' => self::class.'::test_some_other_test', 'request' => 0, 'effect' => 0, 'kind' => 'mail']));
        $recorded = $this->record();

        $this->post('/_failing/order')->assertNoContent();

        $this->assertArrayNotHasKey('fault', $recorded()[0]);
    }

    public function test_what_a_job_on_the_sync_queue_does_is_marked_and_an_uncaught_error_is_still_recorded()
    {
        Route::post('/_failing/queued', function () {
            dispatch(function () {
                User::query()->count();
            });
            User::query()->count();

            return response()->noContent();
        });
        Route::post('/_failing/thrown', function () {
            DB::table('users')->where('id', 0)->update(['name' => 'Thrown']);

            throw new RuntimeException('No error page for this one.');
        });
        $recorded = $this->record();

        $this->post('/_failing/queued')->assertNoContent();
        $this->withoutExceptionHandling();
        rescue(fn () => $this->post('/_failing/thrown'), report: false);

        [$queued, $thrown] = $recorded();
        $this->assertSame([['job', false], ['query', true], ['query', false]], array_map(fn (array $effect) => [$effect['kind'], $effect['job'] ?? false], $queued['effects']));
        $this->assertSame(['/_failing/thrown', 500, true, ['query']], [$thrown['route'], $thrown['status'], $thrown['refused'], array_column($thrown['effects'], 'kind')]);
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
