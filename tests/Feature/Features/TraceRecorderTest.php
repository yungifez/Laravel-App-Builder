<?php

namespace Tests\Feature\Features;

use App\Features\AppFaults;
use App\Features\AppTraces;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
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
use Tests\Fixtures\RecordedCarefulJob;
use Tests\Fixtures\RecordedEvent;
use Tests\Fixtures\RecordedJob;
use Tests\Fixtures\RecordedMail;
use Tests\Fixtures\RecordedMarksReady;
use Tests\Fixtures\RecordedNotice;
use Tests\Fixtures\RecordedQueuedNotice;
use Tests\Fixtures\RecordedResource;
use Tests\Fixtures\RecordedTellsOwner;
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
    protected function recordWithFailure(int $effect, string $kind, ?string $what = null): callable
    {
        putenv('TRACE_RECORDER_FAULT='.json_encode(['test' => self::class.'::'.$this->name(), 'request' => 1, 'effect' => $effect, 'kind' => $kind, ...($what === null ? [] : ['what' => $what])]));

        return $this->record();
    }

    /**
     * Give the app listeners the way Laravel gives it the ones it finds
     * by itself, in a fixed order: the real search takes the disk's order.
     *
     * @param  array<class-string, list<string>>  $found
     */
    protected function discover(array $found): void
    {
        $this->app->register(new class($this->app, $found) extends EventServiceProvider
        {
            /**
             * @param  array<class-string, list<string>>  $found
             */
            public function __construct($app, protected array $found)
            {
                parent::__construct($app);
            }

            public function shouldDiscoverEvents()
            {
                return true;
            }

            public function discoverEvents()
            {
                return $this->found;
            }

            protected function configureEmailVerification()
            {
                //
            }
        });
    }

    /**
     * Charge a person twice, the second time with a server error as the
     * answer of the outside call, and measure that place.
     *
     * @param  array<string, int>  $sent
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    protected function chargeAnsweredWithAnError(array $sent, int $status): array
    {
        $user = User::factory()->create();
        Route::post('/_faked/charged/{user}', [RecordedApp::class, 'charged'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 1, kind: 'answer');
        Http::fake();

        $this->post("/_faked/charged/{$user->id}", $sent)->assertNoContent();
        $this->post("/_faked/charged/{$user->id}", $sent)->assertStatus($status);

        $requests = $recorded();
        // The call reached the fake both times: only its answer was another one.
        Http::assertSentCount(2);

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $this->assertSame([['send', 1, 'http'], ['answer', 1, 'answer'], ['save', 2, 'query']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[1]['fault']['request'] = 1;

        return [$requests, (array) AppFaults::measure($points, [1 => $requests], $this->wholeFilePatch())];
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

    public function test_a_job_of_the_framework_that_delivers_a_notification_is_marked_and_is_no_place_to_run_twice()
    {
        $user = User::factory()->create();
        Route::post('/_failing/told/{user}', [RecordedApp::class, 'told'])->middleware('web');
        Route::post('/_failing/worked', [RecordedApp::class, 'worked']);
        $recorded = $this->record();

        $this->post("/_failing/told/{$user->id}")->assertNoContent();
        $this->post('/_failing/worked')->assertNoContent();

        [$told, $worked] = $recorded();
        $jobs = fn (array $request) => array_values(array_map(fn (array $effect) => [$effect['what'], $effect['delivers'] ?? false], array_filter($request['effects'], fn (array $effect) => $effect['kind'] === 'job')));

        // The job is named as the notification it delivers; the app wrote no code in it.
        $this->assertSame([[[RecordedQueuedNotice::class, true]], [[RecordedJob::class, false]]], [$jobs($told), $jobs($worked)]);
        $this->assertContains('mail', array_column(array_filter($told['effects'], fn (array $effect) => $effect['job'] ?? false), 'kind'));
        $this->assertSame([], AppFaults::points([$told], $this->wholeFilePatch()));
        // The job changes a row after it sends: that save is a place of its own.
        $this->assertSame(['again', 'retry'], array_column(AppFaults::points([$worked], $this->wholeFilePatch()), 'fails'));
    }

    public function test_a_job_tried_again_after_its_save_failed_shows_what_it_sent_both_times()
    {
        User::factory()->create(['name' => 'Careful']);
        Route::post('/_failing/careful', [RecordedApp::class, 'careful']);
        $recorded = $this->recordWithFailure(effect: 3, kind: 'query');

        $this->post('/_failing/careful')->assertNoContent();
        User::query()->where('name', 'Told')->update(['name' => 'Careful']);
        // The job's failure reaches the request here; in use it stays on the queue.
        $this->post('/_failing/careful')->assertStatus(500);

        $requests = $recorded();
        $did = fn (array $request) => array_map(fn (array $effect) => [AppTraces::verb($effect['sql'] ?? '') ?: $effect['kind'], $effect['job'] ?? false, $effect['again'] ?? false], $request['effects']);
        $once = [['job', false, false], ['select', true, false], ['mail', true, false], ['update', true, false]];

        $this->assertSame([$once, [...$once, ['job', true, true], ...array_slice($once, 1)]], [$did($requests[0]), $did($requests[1])]);
        $this->assertSame([null, 3], [$requests[0]['fault'] ?? null, $requests[1]['fault'] ?? null]);
        // The second try saved what the first could not.
        $this->assertSame(1, User::query()->where('name', 'Told')->count());

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $this->assertSame([['again', 0, 'job'], ['retry', 3, 'query']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[1]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [1 => $requests], $this->wholeFilePatch());
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['sent_again', 'POST /_failing/careful', 'job '.RecordedCarefulJob::class, 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_careful_job_made_to_run_twice_whole_sends_once()
    {
        User::factory()->create(['name' => 'Careful']);
        Route::post('/_failing/careful', [RecordedApp::class, 'careful']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'job');

        $this->post('/_failing/careful')->assertNoContent();
        User::query()->where('name', 'Told')->update(['name' => 'Careful']);
        $this->post('/_failing/careful')->assertNoContent();

        $requests = $recorded();
        // The second run asked, saw that it ran before, and stopped.
        $this->assertSame(['job', 'query', 'mail', 'query', 'job', 'query'], array_column($requests[1]['effects'], 'kind'));

        $measured = $this->measureFailure($requests, 'job '.RecordedCarefulJob::class);
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_job_held_back_until_the_response_shows_what_the_request_did_not_do_without_it()
    {
        User::factory()->create(['name' => 'Careful']);
        Route::post('/_failing/waited', [RecordedApp::class, 'waited']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'later');

        $this->post('/_failing/waited')->assertNoContent();
        User::query()->where('name', 'Told')->update(['name' => 'Careful']);
        $this->post('/_failing/waited')->assertNoContent();

        $requests = $recorded();
        $did = fn (array $request) => array_map(fn (array $effect) => [AppTraces::verb($effect['sql'] ?? '') ?: $effect['kind'], $effect['job'] ?? false], $request['effects']);
        $job = [['select', true], ['mail', true], ['update', true]];

        $this->assertSame([
            [['job', false], ...$job, ['select', false], ['mail', false]],
            // The job ran when the response was made: the request found nothing to tell of.
            [['job', false], ['select', false], ...$job],
        ], array_map($did, $requests));
        $this->assertSame([null, 0], [$requests[0]['fault'] ?? null, $requests[1]['fault'] ?? null]);
        $this->assertSame($requests[0]['effects'][0], $requests[1]['effects'][0]);
        // The job still ran: later, not never.
        $this->assertSame(1, User::query()->where('name', 'Told')->count());

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $this->assertSame([['send', 5, 'mail'], ['again', 0, 'job'], ['retry', 3, 'query'], ['later', 0, 'later']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[3]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [3 => $requests], $this->wholeFilePatch());
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['needs_job_done', 'POST /_failing/waited', 'job '.RecordedCarefulJob::class, 'missing mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_job_the_app_sends_to_the_sync_queue_by_name_is_not_held_back()
    {
        Route::post('/_failing/ran', [RecordedApp::class, 'ran']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'later');

        $this->post('/_failing/ran')->assertNoContent();
        $this->post('/_failing/ran')->assertNoContent();

        $requests = $recorded();
        $this->assertSame(['job', 'query', 'mail', 'query'], array_column($requests[1]['effects'], 'kind'));
        $this->assertSame([[null, $requests[0]['effects']]], [[$requests[1]['fault'] ?? null, $requests[1]['effects']]]);
    }

    public function test_an_event_whose_found_listeners_run_in_the_reverse_order_shows_what_the_request_did_not_do()
    {
        User::factory()->create();
        Route::post('/_failing/ordered', [RecordedApp::class, 'ordered']);
        $this->discover([RecordedEvent::class => [RecordedMarksReady::class.'@handle', RecordedTellsOwner::class.'@handle']]);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'event', what: RecordedEvent::class);

        foreach (range(0, 2) as $request) {
            User::query()->update(['name' => 'New']);
            $this->post('/_failing/ordered')->assertNoContent();
        }

        $requests = $recorded();
        $did = fn (array $request) => array_map(fn (array $effect) => AppTraces::verb($effect['sql'] ?? '') ?: $effect['kind'], $request['effects']);

        // Only the request the run names has the other order. The one after it has the app's order again.
        $this->assertSame([['update', 'select', 'mail'], ['select', 'update'], ['update', 'select', 'mail']], array_map($did, $requests));
        $this->assertSame([null, 0, null], array_map(fn (array $request) => $request['fault'] ?? null, $requests));

        $event = $requests[0]['events'][0];
        $this->assertSame([1, RecordedEvent::class, [RecordedMarksReady::class.'::handle', RecordedTellsOwner::class.'::handle']], [count($requests[0]['events']), $event['what'], $event['listeners']]);
        $this->assertStringStartsWith(RecordedApp::PATH.':', $event['at']);
        $this->assertSame($requests[0]['events'], $requests[1]['events']);

        // The line that dispatches the event is the change's, so the place is too.
        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $this->assertSame([['reorder', 'event '.RecordedEvent::class, true], ['send', 'mail message', false]], array_map(fn (array $point) => [$point['fails'], $point['failed'], $point['own']], $points));
        $this->assertSame(['request' => 0, 'effect' => 0, 'kind' => 'event', 'what' => RecordedEvent::class], array_diff_key($points[0]['fault'], ['test' => 1]));
        $points[0]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [0 => $requests], $this->wholeFilePatch());
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['depends_on_order', 'POST /_failing/ordered', 'event '.RecordedEvent::class, 'missing mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_listener_that_fails_in_the_reverse_order_is_still_marked_as_the_place()
    {
        User::factory()->create();
        Route::post('/_failing/ordered', [RecordedApp::class, 'ordered']);
        $this->discover([RecordedEvent::class => [RecordedMarksReady::class.'@handle', RecordedTellsOwner::class.'@strict']]);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'event', what: RecordedEvent::class);

        User::query()->update(['name' => 'New']);
        $this->post('/_failing/ordered')->assertNoContent();
        User::query()->update(['name' => 'New']);
        $this->post('/_failing/ordered')->assertStatus(500);

        $requests = $recorded();
        $this->assertSame([null, 0], [$requests[0]['fault'] ?? null, $requests[1]['fault'] ?? null]);

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $points[0]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [0 => $requests], $this->wholeFilePatch());
        $this->assertSame(['answered 500, not 204, missing update users'], array_column($measured['findings'], 'what'));
    }

    public function test_listeners_the_app_registers_by_hand_keep_their_order_and_are_no_place()
    {
        User::factory()->create();
        Route::post('/_failing/ordered', [RecordedApp::class, 'ordered']);
        Event::listen(RecordedEvent::class, RecordedMarksReady::class);
        Event::listen(RecordedEvent::class, RecordedTellsOwner::class.'@handle');
        $recorded = $this->recordWithFailure(effect: 0, kind: 'event', what: RecordedEvent::class);

        foreach (range(0, 1) as $request) {
            User::query()->update(['name' => 'New']);
            $this->post('/_failing/ordered')->assertNoContent();
        }

        $requests = $recorded();
        $this->assertSame([['query', 'query', 'mail'], ['query', 'query', 'mail']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame([[null, null], [null, null]], array_map(fn (array $request) => [$request['events'] ?? null, $request['fault'] ?? null], $requests));
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

    public function test_an_outside_call_the_app_makes_again_after_no_answer_is_found()
    {
        Route::post('/_faked/retried', [RecordedApp::class, 'retried']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'http');
        Http::fake();

        $this->post('/_faked/retried')->assertNoContent();
        $this->post('/_faked/retried')->assertNoContent();

        $requests = $recorded();
        $this->assertSame([['http'], ['http', 'http']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame([[], []], array_map(fn (array $request) => array_column($request['effects'], 'keyed'), $requests));
        // The first try got no answer; the second try reached the fake.
        Http::assertSentCount(2);

        $measured = $this->measureFailure($requests, 'http POST outside.example');
        $this->assertSame([['called_again', 'http POST outside.example', 'http POST outside.example']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_an_outside_call_that_says_which_call_it_is_is_marked_and_can_be_made_again()
    {
        Route::post('/_faked/retried', [RecordedApp::class, 'retried']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'http');
        Http::fake();

        $this->post('/_faked/retried', ['keyed' => 1])->assertNoContent();
        $this->post('/_faked/retried', ['keyed' => 1])->assertNoContent();

        $requests = $recorded();
        $this->assertSame([[true], [true, true]], array_map(fn (array $request) => array_column($request['effects'], 'keyed'), $requests));
        // Only the name of the header is read: its value is not in the trace.
        $this->assertStringNotContainsString('charge-1', File::get("{$this->directory}/trace.jsonl"));

        $measured = $this->measureFailure($requests, 'http POST outside.example');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_an_outside_call_answered_with_an_error_shows_an_app_that_carries_on_without_asking()
    {
        [$requests, $measured] = $this->chargeAnsweredWithAnError([], 204);

        $this->assertSame([['query', 'http', 'query'], ['query', 'http', 'query']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        // The app's code made the call itself, so it gets the answer.
        $this->assertSame([true, true], [$requests[0]['effects'][1]['direct'] ?? null, $requests[1]['effects'][1]['direct'] ?? null]);
        $this->assertSame([[null, null], [1, null]], array_map(fn (array $request) => [$request['fault'] ?? null, $request['asked'] ?? null], $requests));
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['answer_not_checked', 'POST /_faked/charged/{user}', 'http POST outside.example', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_an_app_that_asks_the_error_answer_how_the_call_went_and_stops_is_clean()
    {
        [$requests, $measured] = $this->chargeAnsweredWithAnError(['careful' => 1], 502);

        $this->assertSame([[null, null], [1, true]], array_map(fn (array $request) => [$request['fault'] ?? null, $request['asked'] ?? null], $requests));
        $this->assertSame(['query', 'http'], array_column($requests[1]['effects'], 'kind'));
        $this->assertSame([1, 0, []], [$measured['run'], $measured['missed'], $measured['findings']]);
    }

    public function test_an_app_that_asks_the_error_answer_and_carries_on_in_its_own_way_is_clean()
    {
        [$requests, $measured] = $this->chargeAnsweredWithAnError(['noted' => 1], 204);

        // The same shape as when the call works, but the app looked.
        $this->assertSame([[null, null], [1, true]], array_map(fn (array $request) => [$request['fault'] ?? null, $request['asked'] ?? null], $requests));
        $this->assertSame(['query', 'http', 'query'], array_column($requests[1]['effects'], 'kind'));
        $this->assertSame(1, User::query()->where('name', 'Unpaid')->count());
        $this->assertSame([1, 0, []], [$measured['run'], $measured['missed'], $measured['findings']]);
    }

    public function test_an_app_that_stops_on_what_the_error_answer_holds_is_clean_without_asking()
    {
        [$requests, $measured] = $this->chargeAnsweredWithAnError(['wary' => 1], 502);

        $this->assertSame([[null, null], [1, null]], array_map(fn (array $request) => [$request['fault'] ?? null, $request['asked'] ?? null], $requests));
        $this->assertSame([1, 0, []], [$measured['run'], $measured['missed'], $measured['findings']]);
    }

    public function test_an_outside_call_other_code_makes_for_the_app_is_no_place_for_an_answer()
    {
        $user = User::factory()->create();
        Route::post('/_faked/relayed/{user}', [RecordedApp::class, 'relayed'])->middleware('web');
        $recorded = $this->record();
        Http::fake();

        $this->post("/_faked/relayed/{$user->id}")->assertNoContent();

        $requests = $recorded();
        $this->assertSame([['query', null], ['http', null], ['query', null]], array_map(fn (array $effect) => [$effect['kind'], $effect['direct'] ?? null], $requests[0]['effects']));
        $this->assertStringStartsWith(RecordedApp::PATH.':', (string) $requests[0]['effects'][1]['at']);

        $points = AppFaults::points($requests, $this->wholeFilePatch());
        $this->assertSame([['send', 1, 'http'], ['save', 2, 'query']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
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
