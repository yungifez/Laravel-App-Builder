<?php

namespace Tests\Feature\Features;

use App\Features\AppFaults;
use App\Features\AppTraces;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\RecordedApp;
use Tests\Fixtures\RecordedJob;
use Tests\Fixtures\RecordedMail;
use Tests\Fixtures\RecordedNotice;
use Tests\Fixtures\RecordedResource;
use Tests\TestCase;
use TraceRecorder\Provider;

/**
 * The recorder that goes into the box image, loaded into this app the way
 * it is loaded into an owner's app: as a service provider. The routes it
 * records are RecordedApp's, which stands in for the owner's code.
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
     * A patch that adds every line of the app that is recorded, so what
     * its routes do is the change's.
     */
    protected function wholeFilePatch(): string
    {
        $path = RecordedApp::PATH;
        $lines = count(file(base_path($path)) ?: []);

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
        Route::post('/_recorded/{user}', [RecordedApp::class, 'renamed'])->middleware('web');
        $recorded = $this->record();

        $this->post("/_recorded/{$user->id}")->assertNoContent();

        [$request] = $recorded();
        $this->assertSame(self::class.'::test_a_request_is_recorded_with_what_it_asked_saved_and_sent_and_where', $request['test']);
        $this->assertSame(['POST', '/_recorded/{user}', 204, false], [$request['method'], $request['route'], $request['status'], $request['refused']]);

        // The transaction that wraps this test is not the request's: only its own counts.
        $kinds = array_map(fn (array $effect) => $effect['kind'].' '.$effect['open'], $request['effects']);
        $this->assertSame(['begin 1', 'query 1', 'mail 1', 'commit 0', 'job 0'], array_slice($kinds, -5));

        // Queries keep their placeholders, and each thing names the line of the app's code it came from.
        $update = collect($request['effects'])->firstWhere('sql', 'update "users" set "name" = ?, "updated_at" = ? where "id" = ?');
        $this->assertMatchesRegularExpression('#^'.preg_quote(RecordedApp::PATH).':\d+$#', $update['at']);
        $this->assertStringNotContainsString('Renamed', File::get("{$this->directory}/trace.jsonl"));

        // The reader finds the mail sent before the saving was finished.
        $measured = AppTraces::measure($recorded(), null, ['POST /_recorded/{user}']);
        $this->assertSame(['sent_before_saved'], array_column($measured['findings'], 'kind'));
        $this->assertStringStartsWith('mail', $measured['findings'][0]['what']);
    }

    public function test_refusals_rollbacks_and_reads_that_save_are_told_apart()
    {
        Route::middleware('web')->group(function () {
            Route::get('/_recorded/read', [RecordedApp::class, 'read']);
            Route::post('/_recorded/denied', [RecordedApp::class, 'denied']);
            Route::post('/_recorded/invalid', [RecordedApp::class, 'invalid']);
            Route::post('/_recorded/undone', [RecordedApp::class, 'undone']);
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
        Route::post('/_failing/order', [RecordedApp::class, 'order']);
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
        Route::post('/_failing/invite', [RecordedApp::class, 'invite']);
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

    public function test_a_save_made_to_fail_outside_a_transaction_is_never_made_and_the_step_before_it_stays()
    {
        Route::post('/_failing/steps', [RecordedApp::class, 'steps']);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'query');

        $this->post('/_failing/steps')->assertNoContent();
        $this->post('/_failing/steps')->assertStatus(500);

        $requests = $recorded();
        $this->assertSame(['query 0', 'query 0'], array_map(fn (array $effect) => $effect['kind'].' '.$effect['open'], $requests[1]['effects']));
        $this->assertSame(1, $requests[1]['fault']);
        // The second step was refused before it ran: the failing request's user is there, with the name it was made with.
        $this->assertSame([1, 1], [User::query()->where('name', 'Second step')->count(), User::query()->where('name', '!=', 'Second step')->count()]);

        $measured = $this->measureFailure($requests, 'update users');
        $this->assertSame([['saved_in_part', 'POST /_failing/steps', 'update users', 'insert users']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_what_the_tests_own_code_does_inside_a_request_is_not_the_apps()
    {
        $user = User::factory()->create();
        Route::post('/_recorded/full/{user}', [RecordedApp::class, 'full'])->middleware('web');
        // The test stands in for a second person, who saves while the request runs.
        $saved = false;
        User::retrieved(function () use (&$saved) {
            $saved = $saved || (bool) User::factory()->create();
        });
        $recorded = $this->record();

        $this->post("/_recorded/full/{$user->id}")->assertStatus(422);

        [$request] = $recorded();
        $writes = array_values(array_filter($request['effects'], AppTraces::writes(...)));
        $this->assertSame(['insert users', 'update users'], array_map(fn (array $effect) => AppTraces::statement($effect['sql']), $writes));
        $this->assertNull($writes[0]['at']);
        $this->assertStringStartsWith(RecordedApp::PATH.':', $writes[1]['at']);

        // Only what the app kept is held against it.
        $this->assertSame(['update users'], array_column(AppTraces::measure([$request], null, ['POST /_recorded/full/{user}'])['findings'], 'what'));
    }

    public function test_each_thing_names_the_part_of_the_request_it_happened_in_and_the_apps_code_on_the_way()
    {
        $user = User::factory()->create();
        Route::post('/_recorded/parts/{user}', [RecordedApp::class, 'parts'])->middleware('web');
        Route::post('/_failing/queued', [RecordedApp::class, 'queued']);
        Route::post('/_failing/thrown', [RecordedApp::class, 'thrown']);
        Gate::define('record', [RecordedApp::class, 'allowed']);
        User::saved([RecordedApp::class, 'watched']);
        Event::listen('recorded', [RecordedApp::class, 'heard']);
        $this->app->make(ExceptionHandler::class)->reportable(Closure::fromCallable([new RecordedApp, 'reported']));
        $recorded = $this->record();

        $this->actingAs($user)->post("/_recorded/parts/{$user->id}", ['name' => $user->name])->assertOk();
        $this->post('/_failing/queued')->assertNoContent();
        $this->post('/_failing/thrown')->assertStatus(500);

        [$parts, $queued, $thrown] = $recorded();
        $app = fn (string ...$methods) => array_map(fn (string $method) => RecordedApp::class.'::'.$method, $methods);
        $seen = fn (array $request) => array_map(fn (array $effect) => [$effect['phase'], AppTraces::verb($effect['sql'] ?? '') ?: $effect['kind'], $effect['frames']], array_values(array_filter($request['effects'], fn (array $effect) => isset($effect['phase']))));

        $this->assertSame([
            // The framework finds the person of the route by itself.
            ['middleware', 'select', []],
            ['authorization', 'select', $app('allowed', 'parts')],
            ['validation', 'select', $app('parts')],
            ['handling', 'update', $app('parts')],
            ['model', 'select', $app('watched', 'parts')],
            // A closure is named as the method it is written in.
            ['listener', 'select', $app('heard', 'parts')],
            // The answer is built after the route's own code has returned.
            ['rendering', 'select', [RecordedResource::class.'::toArray']],
        ], $seen($parts));
        $this->assertSame([['handling', 'job', $app('queued')], ['job', 'select', $app('queued')], ['handling', 'select', $app('queued')]], $seen($queued));
        $this->assertSame([['handling', 'update', $app('thrown')], ['error', 'select', $app('reported')]], $seen($thrown));
    }

    public function test_nothing_fails_for_another_test_or_another_kind_of_thing()
    {
        Route::post('/_failing/order', [RecordedApp::class, 'receipt']);
        putenv('TRACE_RECORDER_FAULT='.json_encode(['test' => self::class.'::test_some_other_test', 'request' => 0, 'effect' => 0, 'kind' => 'mail']));
        $recorded = $this->record();

        $this->post('/_failing/order')->assertNoContent();

        $this->assertArrayNotHasKey('fault', $recorded()[0]);
    }

    public function test_what_a_job_on_the_sync_queue_does_is_marked_and_an_uncaught_error_is_still_recorded()
    {
        Route::post('/_failing/queued', [RecordedApp::class, 'queued']);
        Route::post('/_failing/thrown', [RecordedApp::class, 'thrown']);
        $recorded = $this->record();

        $this->post('/_failing/queued')->assertNoContent();
        $this->withoutExceptionHandling();
        rescue(fn () => $this->post('/_failing/thrown'), report: false);

        [$queued, $thrown] = $recorded();
        $this->assertSame([['job', false], ['query', true], ['query', false]], array_map(fn (array $effect) => [$effect['kind'], $effect['job'] ?? false], $queued['effects']));
        $this->assertSame(['/_failing/thrown', 500, true, ['query']], [$thrown['route'], $thrown['status'], $thrown['refused'], array_column($thrown['effects'], 'kind')]);
    }

    public function test_a_job_made_to_run_twice_shows_what_it_sent_and_added_both_times()
    {
        Route::post('/_failing/worked', [RecordedApp::class, 'worked']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'job');

        $this->post('/_failing/worked')->assertNoContent();
        // The second run is the queue's, not the person's: the request ends as usual.
        $this->post('/_failing/worked')->assertNoContent();
        // One job only: the request after it runs as usual.
        $this->post('/_failing/worked')->assertNoContent();

        $requests = $recorded();
        $did = fn (array $request) => array_map(fn (array $effect) => [AppTraces::verb($effect['sql'] ?? '') ?: $effect['kind'], $effect['job'] ?? false, $effect['again'] ?? false], $request['effects']);
        $once = [['job', false, false], ['insert', true, false], ['mail', true, false], ['update', true, false]];

        $this->assertSame([false, true, false], array_map(fn (array $request) => isset($request['fault']), $requests));
        $this->assertSame([$once, $once], [$did($requests[0]), $did($requests[2])]);
        $this->assertSame([...$once, ['job', true, true], ...array_slice($once, 1)], $did($requests[1]));
        $this->assertSame(4, User::query()->where('name', 'Worked')->count());

        $measured = $this->measureFailure($requests, 'job '.RecordedJob::class);
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        // The change to a row is made again, which leaves the row as it was.
        $this->assertSame([['done_twice', 'POST /_failing/worked', 'job '.RecordedJob::class, 'insert users, mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_an_email_a_test_fakes_is_still_seen_and_the_fake_still_holds_it()
    {
        $user = User::factory()->create();
        Route::post('/_faked/welcome/{user}', [RecordedApp::class, 'welcome'])->middleware('web');
        $recorded = $this->record();
        $fake = Mail::fake();

        $this->post("/_faked/welcome/{$user->id}")->assertNoContent();

        [$request] = $recorded();
        $this->assertSame([['query', null], ['mail', RecordedMail::class]], array_map(fn (array $effect) => [$effect['kind'], $effect['what'] ?? null], array_slice($request['effects'], -2)));
        $this->assertStringStartsWith(RecordedApp::PATH.':', $request['effects'][array_key_last($request['effects'])]['at']);
        $this->assertSame([], $request['blind']);

        // The test's own assertions read the same fake, by the facade and by the one it kept.
        Mail::assertSent(RecordedMail::class, 'guest@example.com');
        $fake->assertSentCount(1);
    }

    public function test_an_email_made_to_fail_under_a_fake_ends_the_request_in_an_error_and_what_it_saved_stays()
    {
        $user = User::factory()->create();
        Route::post('/_faked/welcome/{user}', [RecordedApp::class, 'welcome'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 2, kind: 'mail');
        Mail::fake();

        $this->post("/_faked/welcome/{$user->id}")->assertNoContent();
        $this->post("/_faked/welcome/{$user->id}")->assertStatus(500);

        $requests = $recorded();
        $this->assertSame(['query', 'query', 'mail'], array_column($requests[0]['effects'], 'kind'));
        $this->assertSame(2, $requests[1]['fault']);
        // The email that failed never reached the fake.
        Mail::assertSentCount(1);

        $measured = $this->measureFailure($requests, 'mail '.RecordedMail::class);
        $this->assertSame([['saved_then_failed', 'mail '.RecordedMail::class, 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_an_outside_call_a_test_fakes_gets_no_answer_and_what_the_request_saved_stays()
    {
        $user = User::factory()->create();
        Route::post('/_faked/called/{user}', [RecordedApp::class, 'called'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 2, kind: 'http');
        Http::fake();

        $this->post("/_faked/called/{$user->id}")->assertNoContent();
        $this->post("/_faked/called/{$user->id}")->assertStatus(500);

        $requests = $recorded();
        $this->assertSame([['query', 'query', 'http'], ['query', 'query', 'http']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame([[], []], array_column($requests, 'blind'));
        // The call that got no answer never reached the fake.
        Http::assertSentCount(1);

        $measured = $this->measureFailure($requests, 'http POST outside.example');
        $this->assertSame([['saved_then_failed', 'http POST outside.example', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_notification_a_test_fakes_is_seen_as_the_email_it_sends_and_that_email_can_fail()
    {
        $user = User::factory()->create();
        Route::post('/_faked/noticed/{user}', [RecordedApp::class, 'noticed'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 3, kind: 'mail');
        Notification::fake();

        $this->post("/_faked/noticed/{$user->id}")->assertNoContent();
        $this->post("/_faked/noticed/{$user->id}")->assertStatus(500);
        // A channel that saves is not stood in for: the fake hid that save.
        $this->post("/_faked/noticed/{$user->id}", ['channel' => 'database'])->assertNoContent();

        $requests = $recorded();
        $this->assertSame([['query', 'query', 'notification', 'mail'], ['query', 'query', 'notification', 'mail'], ['query', 'query', 'notification']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame([[], [], ['notifications']], array_column($requests, 'blind'));
        Notification::assertSentTo($user, RecordedNotice::class);

        $measured = $this->measureFailure($requests, 'mail '.RecordedNotice::class);
        $this->assertSame([['saved_then_failed', 'mail '.RecordedNotice::class, 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_job_a_test_fakes_is_seen_when_the_queue_takes_it()
    {
        Route::post('/_faked/later', [RecordedApp::class, 'later']);
        $recorded = $this->record();
        Queue::fake();

        $this->post('/_faked/later')->assertNoContent();
        Bus::fake();
        $this->post('/_faked/later')->assertNoContent();

        // The job that waits for the transaction is taken when it commits, as the queue takes it.
        $expected = [['begin', 1], ['job', 1], ['query', 1], ['job', 0], ['commit', 0]];
        [$queue, $bus] = $recorded();
        $this->assertSame([$expected, $expected], array_map(fn (array $request) => array_map(fn (array $effect) => [$effect['kind'], $effect['open']], $request['effects']), [$queue, $bus]));
        $this->assertSame([RecordedJob::class, []], [$queue['effects'][1]['what'], $queue['blind']]);
        $this->assertSame([RecordedJob::class, []], [$bus['effects'][1]['what'], $bus['blind']]);
        Queue::assertPushed(RecordedJob::class, 2);
        Bus::assertDispatched(RecordedJob::class, 2);
    }

    public function test_a_fake_that_hides_what_is_sent_is_named()
    {
        Route::post('/_recorded/quiet', [RecordedApp::class, 'quiet']);
        Route::post('/_faked/ran', [RecordedApp::class, 'ran']);
        $recorded = $this->record();
        Event::fake();

        $this->post('/_recorded/quiet')->assertNoContent();
        // A job the app runs before it answers did not run under the fake.
        Bus::fake();
        $this->post('/_recorded/quiet')->assertNoContent();
        $this->post('/_faked/ran')->assertNoContent();

        $this->assertSame([['events'], ['events'], ['events', 'jobs']], array_column($recorded(), 'blind'));
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
