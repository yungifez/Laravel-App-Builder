<?php

namespace Tests\Feature\Features;

use App\Features\AppFaults;
use App\Features\AppTraces;
use App\Models\User;
use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Fixtures\RecordedApp;
use Tests\Fixtures\RecordedCarefulJob;
use Tests\Fixtures\RecordedCommand;
use Tests\Fixtures\RecordedEagerJob;
use Tests\Fixtures\RecordedEvent;
use Tests\Fixtures\RecordedHushedJob;
use Tests\Fixtures\RecordedJob;
use Tests\Fixtures\RecordedMail;
use Tests\Fixtures\RecordedMarksReady;
use Tests\Fixtures\RecordedNotice;
use Tests\Fixtures\RecordedPersonalJob;
use Tests\Fixtures\RecordedQueuedListener;
use Tests\Fixtures\RecordedQueuedNotice;
use Tests\Fixtures\RecordedResource;
use Tests\Fixtures\RecordedRoundJob;
use Tests\Fixtures\RecordedTellsOwner;
use Tests\TestCase;
use TraceRecorder\Provider;
use TraceRecorder\Recorder;

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
     * Thank a signed-in person three times with a queued job. The second
     * time the job is held back and runs the way a worker runs it.
     * Measure that place.
     *
     * @param  array<string, string>  $sent
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    protected function thankOnAQueue(array $sent): array
    {
        $user = User::factory()->create();
        Route::post('/_failing/thanked', [RecordedApp::class, 'thanked']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'later');
        $this->actingAs($user);

        foreach (range(0, 2) as $request) {
            $this->post('/_failing/thanked', $sent)->assertNoContent();
        }

        // The worker's empty request was only for the job: the person is still signed in.
        $this->assertSame([$user->id, '/_failing/thanked'], [auth()->id(), '/'.request()->path()]);

        $requests = $recorded();
        $this->assertSame([null, 0, null], array_map(fn (array $request) => $request['fault'] ?? null, $requests));

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $this->assertSame([['again', 0, 'job'], ['later', 0, 'later'], ['send', 1, 'mail']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[1]['fault']['request'] = 1;

        return [$requests, (array) AppFaults::measure($points, [1 => $requests], $this->wholeFilePatch())];
    }

    /**
     * A patch that adds every line of the app that is recorded, so what
     * its routes do is the change's.
     */
    protected function wholeFilePatch(string $path = RecordedApp::PATH): string
    {
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
    protected function measureFailure(array $requests, string $failed, string $path = RecordedApp::PATH): array
    {
        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch($path));
        $position = array_search($failed, array_column($points, 'failed'), true);
        $this->assertNotFalse($position);
        $this->assertSame($requests[1]['fault'], $points[$position]['fault']['effect']);
        $points[$position]['fault']['request'] = 1;

        return (array) AppFaults::measure($points, [$position => $requests], $this->wholeFilePatch($path));
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

    public function test_a_kind_of_thing_named_in_the_recording_folder_fails_in_every_request_until_the_file_goes()
    {
        Route::post('/_failing/order', [RecordedApp::class, 'order']);
        $recorded = $this->record();

        $this->post('/_failing/order')->assertNoContent();

        // The owner of the app on show says its email is down: each email fails from now on.
        File::put("{$this->directory}/fault.json", json_encode(['kind' => 'mail']));
        $this->post('/_failing/order')->assertStatus(500);
        $this->post('/_failing/order')->assertStatus(500);

        // A kind that cannot be made to fail, or a file that says nothing, lets all work.
        File::put("{$this->directory}/fault.json", json_encode(['kind' => 'query']));
        $this->post('/_failing/order')->assertNoContent();
        File::put("{$this->directory}/fault.json", json_encode(['kind' => null]));
        $this->post('/_failing/order')->assertNoContent();

        $requests = $recorded();
        $this->assertSame([204, 500, 500, 204, 204], array_column($requests, 'status'));
        $this->assertSame([false, true, true, false, false], array_map(fn (array $request) => isset($request['fault']), $requests));
        $this->assertSame(1, $requests[1]['fault']);
        // The email that failed is marked in the trace as written, so the owner reads which one it was.
        $written = array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", File::get("{$this->directory}/trace.jsonl"))));
        $this->assertSame([false, true], array_map(fn (array $effect) => $effect['failed'] ?? false, $written[1]['effects']));
    }

    public function test_the_clock_named_in_the_recording_folder_moves_the_app_ahead_and_runs_on_from_there()
    {
        Route::get('/_clock', fn () => now()->toIso8601String());
        $this->record();
        $today = now();

        // The owner moves their app on show a week ahead: each request starts in that week.
        File::put("{$this->directory}/clock.json", json_encode(['ahead' => 7 * 86400]));
        $first = Date::parse($this->get('/_clock')->getContent());
        $this->assertTrue($first->between($today->addWeek()->subMinute(), $today->addWeek()->addMinute()));
        // The clock is not frozen there: it runs on as the real one does.
        usleep(1_100_000);
        $this->assertTrue(Date::parse($this->get('/_clock')->getContent())->greaterThan($first));

        // Back to today.
        File::put("{$this->directory}/clock.json", json_encode(['ahead' => 0]));
        $this->assertTrue(Date::parse($this->get('/_clock')->getContent())->between($today->subMinute(), $today->addMinute()));
    }

    public function test_a_test_that_moves_time_itself_keeps_its_own_clock()
    {
        Route::get('/_clock', fn () => now()->toDateString());
        $this->record();

        $this->travelTo('2031-05-04 10:00:00');
        $this->assertSame('2031-05-04', $this->get('/_clock')->getContent());

        // A file that says nothing, or nonsense, moves nothing either.
        File::put("{$this->directory}/clock.json", json_encode(['ahead' => 'soon']));
        $this->assertSame('2031-05-04', $this->get('/_clock')->getContent());
    }

    public function test_the_last_form_the_app_on_show_took_is_kept_without_passwords_to_send_again()
    {
        Route::middleware('web')->post('/_twice/books', fn () => redirect('/'));
        Route::middleware('web')->post('/_twice/refused', fn () => abort(422));
        Route::middleware('web')->get('/_twice/books', fn () => 'list');
        $this->record();
        // In use, as the app on show runs; its forms carry their token there.
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        $kept = fn () => json_decode((string) @file_get_contents("{$this->directory}/last-send.json"), true);

        $this->post('/_twice/books?shelf=2', ['title' => 'Dune', 'password' => 'secret', 'password_confirmation' => 'secret'])->assertRedirect('/');

        $send = $kept();
        $this->assertSame(['POST', '/_twice/books?shelf=2', '/_twice/books'], [$send['method'], $send['path'], $send['route']]);
        // Passwords never reach the disk; the rest is sent again as it was.
        $this->assertSame(['title' => 'Dune', 'shelf' => '2'], $send['input']);
        $this->assertSame(session()->getId(), $send['session']);
        $this->assertSame(session()->token(), $send['token']);

        // A page read, a send the app turned down, or one with a file leaves the kept one alone.
        $this->get('/_twice/books')->assertOk();
        $this->post('/_twice/refused', ['title' => 'Emma'])->assertStatus(422);
        $this->post('/_twice/books', ['title' => 'Emma', 'cover' => UploadedFile::fake()->create('cover.jpg')])->assertRedirect('/');
        $this->assertSame(['title' => 'Dune', 'shelf' => '2'], $kept()['input']);
    }

    public function test_no_form_is_kept_while_the_apps_own_tests_run()
    {
        Route::middleware('web')->post('/_twice/books', fn () => redirect('/'));
        $this->record();

        $this->post('/_twice/books', ['title' => 'Dune'])->assertRedirect('/');

        $this->assertFileDoesNotExist("{$this->directory}/last-send.json");
    }

    public function test_a_page_in_use_says_how_long_it_took_and_how_often_it_asked_the_database()
    {
        Route::middleware('web')->get('/_slow/books', fn () => count([DB::select('select 1'), DB::select('select 2'), DB::select('select 3')]));
        $this->record();
        $this->app['env'] = 'local';

        $this->get('/_slow/books')->assertOk();

        $written = array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", File::get("{$this->directory}/trace.jsonl"))));
        $this->assertIsInt($written[0]['ms']);
        $this->assertGreaterThanOrEqual(0, $written[0]['ms']);
        $this->assertSame(3, $written[0]['lookups']);
    }

    public function test_no_time_is_written_while_the_apps_own_tests_run()
    {
        Route::middleware('web')->get('/_slow/books', fn () => count(DB::select('select 1')));
        $this->record();

        $this->get('/_slow/books')->assertOk();

        $written = json_decode(explode("\n", File::get("{$this->directory}/trace.jsonl"))[0], true);
        $this->assertArrayNotHasKey('ms', $written);
        $this->assertArrayNotHasKey('lookups', $written);
    }

    public function test_a_failure_made_up_for_the_owner_points_at_their_app_and_never_at_our_recorder()
    {
        Route::post('/_failing/order', [RecordedApp::class, 'order']);
        $this->record();
        File::put("{$this->directory}/fault.json", json_encode(['kind' => 'mail']));

        try {
            $this->withoutExceptionHandling()->post('/_failing/order');
            $this->fail('The email did not fail.');
        } catch (TransportException $error) {
            // The error page the owner reads lists these places; none may name a tool of ours.
            $recorder = base_path('resources/trace-recorder');
            $places = [$error->getFile(), ...array_map(fn (array $frame) => ($frame['file'] ?? '').' '.($frame['class'] ?? ''), $error->getTrace())];

            $this->assertSame([], array_values(array_filter($places, fn (string $place) => str_contains($place, $recorder) || str_contains($place, 'TraceRecorder\\'))));
            $this->assertNotSame(0, $error->getLine());
            $this->assertStringContainsString('could not be established', $error->getMessage());
        }
    }

    public function test_what_the_app_keeps_for_later_is_recorded_and_the_cache_can_be_down_while_the_app_is_in_use()
    {
        Route::post('/_failing/remembered', [RecordedApp::class, 'remembered']);
        $recorded = $this->record();

        $this->post('/_failing/remembered')->assertNoContent();

        // The cache server is down: the first read, write or forget fails the request.
        File::put("{$this->directory}/fault.json", json_encode(['kind' => 'cache']));
        $this->post('/_failing/remembered')->assertStatus(500);
        // The app's log says the cache was down on purpose, for whoever reads the error.
        $this->assertSame(Recorder::LIVE_WORDS['cache'], Context::get(Recorder::LIVE_CONTEXT));
        File::put("{$this->directory}/fault.json", json_encode(['kind' => null]));
        $this->post('/_failing/remembered')->assertNoContent();
        $this->assertFalse(Context::has(Recorder::LIVE_CONTEXT));

        $requests = $recorded();
        $this->assertSame([204, 500, 204], array_column($requests, 'status'));
        // A read is one of many a request makes; it is noted only when it fails.
        $this->assertSame([['cache', 'write'], ['cache', 'forget']], array_map(fn (array $effect) => [$effect['kind'], $effect['what']], $requests[0]['effects']));
        $this->assertSame([['cache', 'write']], array_map(fn (array $effect) => [$effect['kind'], $effect['what']], $requests[1]['effects']));
        $this->assertSame(0, $requests[1]['fault']);
    }

    public function test_a_notification_can_fail_while_the_app_is_in_use_and_in_a_run()
    {
        $user = User::factory()->create();
        Route::post('/_failing/noticed/{user}', [RecordedApp::class, 'noticed'])->middleware('web');
        $recorded = $this->record();

        $this->post("/_failing/noticed/{$user->id}", ['channel' => 'database'])->assertNoContent();

        // The notification service does not answer: the notice is not left, and the request fails.
        File::put("{$this->directory}/fault.json", json_encode(['kind' => 'notification']));
        $this->post("/_failing/noticed/{$user->id}", ['channel' => 'database'])->assertStatus(500);

        $requests = $recorded();
        $this->assertSame([204, 500], array_column($requests, 'status'));
        $this->assertContains(['notification', RecordedNotice::class], array_map(fn (array $effect) => [$effect['kind'], $effect['what'] ?? null], $requests[0]['effects']));
        $this->assertSame('notification', $requests[1]['effects'][$requests[1]['fault']]['kind']);
        // The notice that was not left was not saved either.
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_a_cache_write_of_the_apps_own_code_is_a_place_a_run_makes_fail_and_a_save_before_it_is_found()
    {
        $user = User::factory()->create();
        Route::post('/_failing/kept/{user}', [RecordedApp::class, 'kept']);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'cache');

        $this->post("/_failing/kept/{$user->id}")->assertNoContent();
        $this->post("/_failing/kept/{$user->id}")->assertStatus(500);

        $requests = $recorded();
        $this->assertSame([['query', 'cache'], ['query', 'cache']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame(1, $requests[1]['fault']);

        $sends = fn (array $request): array => array_values(array_map(fn (array $point) => [$point['fault']['effect'], $point['fault']['kind']], array_filter(AppFaults::points([$request], $this->wholeFilePatch()), fn (array $point) => $point['fails'] === 'send')));
        $this->assertSame([[1, 'cache']], $sends($requests[0]));

        // The save stayed and the person saw an error, over a copy the cache keeps.
        $measured = $this->measureFailure($requests, 'cache write');
        $this->assertSame([['saved_then_failed', 'cache write', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('rescue(fn () => Cache::put(...))', AppFaults::finding($measured['findings'][0]));
    }

    public function test_an_app_that_goes_on_without_a_cache_that_is_down_did_right()
    {
        $user = User::factory()->create();
        Route::post('/_failing/kept/{user}', [RecordedApp::class, 'keptSafely']);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'cache');

        $this->post("/_failing/kept/{$user->id}")->assertNoContent();
        $this->post("/_failing/kept/{$user->id}")->assertNoContent();

        $requests = $recorded();
        $this->assertSame(1, $requests[1]['fault']);

        // Nothing was hidden: the cache is a copy, and the page still answered.
        $measured = $this->measureFailure($requests, 'cache write');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_notification_is_a_place_a_run_makes_fail_unless_it_is_the_email_that_follows_it()
    {
        $user = User::factory()->create();
        Route::post('/_failing/noticed/{user}', [RecordedApp::class, 'noticed'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 2, kind: 'notification');

        $this->post("/_failing/noticed/{$user->id}", ['channel' => 'database'])->assertNoContent();
        $this->post("/_failing/noticed/{$user->id}", ['channel' => 'database'])->assertStatus(500);
        $this->post("/_failing/noticed/{$user->id}", ['channel' => 'mail'])->assertNoContent();

        $requests = $recorded();
        $this->assertSame(2, $requests[1]['fault']);

        // A notice kept in the database is a place of its own. One that goes
        // out by email is the email: that is the one place.
        $sends = fn (array $request): array => array_values(array_map(fn (array $point) => [$point['fault']['effect'], $point['fault']['kind']], array_filter(AppFaults::points([$request], $this->wholeFilePatch()), fn (array $point) => $point['fails'] === 'send')));
        $this->assertSame([[2, 'notification']], $sends($requests[0]));
        $this->assertSame(['query', 'query', 'notification', 'mail'], array_column($requests[2]['effects'], 'kind'));
        $this->assertSame([[3, 'mail']], $sends($requests[2]));

        $measured = $this->measureFailure($requests, 'notification '.RecordedNotice::class);
        $this->assertSame([['saved_then_failed', 'notification '.RecordedNotice::class, 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_full_trace_is_moved_aside_so_an_app_in_use_cannot_fill_the_disk()
    {
        Route::post('/_failing/order', [RecordedApp::class, 'receipt']);
        $this->record();

        // A trace that has grown past its bound, as a busy app on show leaves.
        File::put("{$this->directory}/trace.old.jsonl", "older\n");
        File::put("{$this->directory}/trace.jsonl", str_repeat('x', Recorder::MAX_BYTES + 1)."\n");

        $this->post('/_failing/order')->assertNoContent();

        $lines = array_filter(explode("\n", File::get("{$this->directory}/trace.jsonl")));
        $this->assertCount(1, $lines, 'The new trace holds only the request after the move.');
        $this->assertSame('POST', json_decode(reset($lines), true)['method']);
        $this->assertSame(Recorder::MAX_BYTES + 2, File::size("{$this->directory}/trace.old.jsonl"), 'The full trace took the place of the one before it.');
        // Two traces at most: the folder stays within twice the bound.
        $this->assertSame(['trace.jsonl', 'trace.old.jsonl'], array_values(array_filter(array_map('basename', File::files($this->directory)), fn (string $name) => str_starts_with($name, 'trace'))));
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

    public function test_a_request_is_recorded_when_the_test_turned_off_all_middleware()
    {
        Route::post('/_loose/order', [RecordedApp::class, 'order']);
        Route::get('/_loose/outer', fn () => tap(response()->noContent(), fn () => app('router')->dispatch(Request::create('/_loose/inner'))));
        Route::get('/_loose/inner', [RecordedApp::class, 'quiet']);
        $recorded = $this->record();
        // Each test of a Livewire component does this.
        $this->withoutMiddleware();

        $this->post('/_loose/order')->assertNoContent();
        $this->get('/_loose/outer')->assertNoContent();
        $this->withMiddleware();
        $this->post('/_loose/order')->assertNoContent();

        $this->assertSame([
            [0, 'POST', '/_loose/order', 204, ['query', 'mail']],
            // A request the app makes to itself is part of the request around it.
            [1, 'GET', '/_loose/outer', 204, []],
            [2, 'POST', '/_loose/order', 204, ['query', 'mail']],
        ], array_map(fn (array $request) => [$request['n'], $request['method'], $request['route'], $request['status'], array_column($request['effects'], 'kind')], $recorded()));
    }

    public function test_a_request_that_ends_in_an_error_with_all_middleware_off_is_recorded_as_the_error_page_the_person_gets()
    {
        Route::post('/_loose/order', [RecordedApp::class, 'order']);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'mail');
        $this->withoutMiddleware()->withoutExceptionHandling();

        $this->post('/_loose/order')->assertNoContent();
        rescue(fn () => $this->post('/_loose/order'), report: false);
        // No answer came for the request. Its trace is written when the next one starts.
        $this->assertCount(1, $recorded());
        $this->post('/_loose/order')->assertNoContent();

        $requests = $recorded();
        $this->assertSame([[204, false], [500, true], [204, false]], array_map(fn (array $request) => [$request['status'], $request['refused']], $requests));
        $this->assertSame(['/_loose/order', 1], [$requests[1]['route'], $requests[1]['fault']]);

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([['saved_then_failed', 'mail message', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_an_error_a_test_let_through_with_all_middleware_off_is_recorded_with_the_status_of_its_error_page()
    {
        Route::post('/_failing/denied', [RecordedApp::class, 'denied']);
        Route::post('/_failing/invalid', [RecordedApp::class, 'invalid']);
        Route::post('/_failing/thrown', [RecordedApp::class, 'thrown']);
        Route::post('/_failing/quiet', [RecordedApp::class, 'quiet']);
        $recorded = $this->record();
        $this->withoutMiddleware()->withoutExceptionHandling();

        rescue(fn () => $this->post('/_failing/denied'), report: false);
        rescue(fn () => $this->post('/_failing/invalid'), report: false);
        rescue(fn () => $this->post('/_failing/thrown'), report: false);
        // The status of one request is not kept for the next one.
        $this->post('/_failing/quiet')->assertNoContent();
        $this->withExceptionHandling()->post('/_failing/denied')->assertForbidden();

        $this->assertSame([
            ['/_failing/denied', 403, true],
            ['/_failing/invalid', 422, true],
            ['/_failing/thrown', 500, true],
            ['/_failing/quiet', 204, false],
            ['/_failing/denied', 403, true],
        ], array_map(fn (array $request) => [$request['route'], $request['status'], $request['refused']], $recorded()));
    }

    public function test_a_request_no_answer_came_for_is_written_when_the_next_test_starts_its_app()
    {
        Route::post('/_failing/thrown', [RecordedApp::class, 'thrown']);
        $recorded = $this->record();
        $this->withoutMiddleware()->withoutExceptionHandling();

        rescue(fn () => $this->post('/_failing/thrown'), report: false);
        $this->assertSame([], $recorded());
        // The next test starts the recorder again, in its own app.
        (new Recorder($this->app, $this->directory))->listen();

        $this->assertSame([['/_failing/thrown', 500, true, ['query']]], array_map(fn (array $request) => [$request['route'], $request['status'], $request['refused'], array_column($request['effects'], 'kind')], $recorded()));
    }

    public function test_a_livewire_request_is_named_by_its_component_and_what_it_calls()
    {
        Route::post('/_wire/update', [RecordedApp::class, 'quiet']);
        $recorded = $this->record();
        $this->withoutMiddleware();

        $component = fn (string $name, string ...$calls) => [
            'snapshot' => json_encode(['data' => ['email' => 'owner@example.com'], 'memo' => ['id' => 'a1', 'name' => $name]]),
            'calls' => array_map(fn (string $method) => ['method' => $method, 'params' => []], $calls),
            'updates' => [],
        ];
        $wire = fn (array ...$components) => $this->post('/_wire/update', ['components' => $components], ['X-Livewire' => '1'])->assertNoContent();

        $wire($component('send-receipt', 'send'));
        $wire($component('orders.table'));
        $this->withMiddleware();
        $wire($component('send-receipt', 'send', 'send', '$refresh'), $component('pages::cart', 'add', 'remove', 'clear', 'pay'));
        $wire($component('one'), $component('two'), $component('three'), $component('four'));
        // A name no component can have, and a request that is not Livewire's.
        $wire($component('send receipt', 'send'));
        $this->post('/_wire/update', ['components' => [$component('send-receipt', 'send')]])->assertNoContent();

        $this->assertSame([
            '/_wire/update#send-receipt@send',
            '/_wire/update#orders.table',
            '/_wire/update#send-receipt@send,$refresh+pages::cart@add,remove,clear,more',
            '/_wire/update#one+two+three+more',
            '/_wire/update',
            '/_wire/update',
        ], array_column($recorded(), 'route'));
    }

    public function test_a_livewire_component_a_test_renders_has_the_same_name_in_every_run()
    {
        $page = fn (string $name) => '<div wire:snapshot="'.e(json_encode(['data' => [], 'memo' => ['name' => $name]])).'" wire:id="a1"><p wire:snapshot="'.e(json_encode(['memo' => ['name' => 'inner']])).'"></p></div>';
        // Livewire's test helper keeps the component it renders in the route's closure.
        $render = function (mixed $name, ?string $shown) use ($page): string {
            $params = [];
            Route::get($address = '/livewire-unit-test-endpoint/'.Str::random(20), function () use ($name, $params, $page, $shown) {
                return $shown === null
                    ? throw new RuntimeException('No component for '.get_debug_type($name).' with '.count($params).' values.')
                    : response($page($shown));
            });

            return $address;
        };
        $recorded = $this->record();
        $this->withoutMiddleware()->withoutExceptionHandling();

        $this->get($render(RecordedApp::class, 'send-receipt'))->assertOk();
        $this->get($render('send-receipt', 'send-receipt'))->assertOk();
        // No answer came: the name is the one the test asked for.
        rescue(fn () => $this->get($render(RecordedApp::class, null)), report: false);
        rescue(fn () => $this->get($render(new RecordedApp, null)), report: false);
        rescue(fn () => $this->get($render(['not a name'], null)), report: false);
        $this->withMiddleware()->get($render('orders.table', 'orders.table'))->assertOk();

        $this->assertSame([
            ['/livewire-unit-test-endpoint#send-receipt', 200],
            ['/livewire-unit-test-endpoint#send-receipt', 200],
            ['/livewire-unit-test-endpoint#'.RecordedApp::class, 500],
            ['/livewire-unit-test-endpoint#'.RecordedApp::class, 500],
            ['/livewire-unit-test-endpoint', 500],
            ['/livewire-unit-test-endpoint#orders.table', 200],
        ], array_map(fn (array $request) => [$request['route'], $request['status']], $recorded()));
    }

    public function test_an_artisan_command_of_the_app_is_recorded_the_way_a_request_is()
    {
        Artisan::registerCommand(new RecordedCommand);
        Route::post('/_command/inside', fn () => tap(response()->noContent(), fn () => Artisan::call('recorded:remind')));
        $recorded = $this->record();

        $this->artisan('recorded:remind')->assertSuccessful();
        // A command of the framework is not the app's work.
        $this->artisan('env')->assertSuccessful();
        // A command the app runs inside a request or another command is part of that one.
        $this->post('/_command/inside')->assertNoContent();
        $this->artisan('recorded:remind --inner')->assertSuccessful();
        // An error ends this one.
        rescue(fn () => $this->artisan('recorded:remind --broken')->run(), report: false);
        $this->artisan(RecordedCommand::class)->assertSuccessful();

        $requests = $recorded();
        $once = ['query', 'mail', 'query'];
        $this->assertSame([
            [0, 'ARTISAN', 'recorded:remind', 200, false, $once],
            [1, 'POST', '/_command/inside', 204, false, $once],
            [2, 'ARTISAN', 'recorded:remind', 200, false, [...$once, ...$once]],
            [3, 'ARTISAN', 'recorded:remind', 500, true, []],
            [4, 'ARTISAN', 'recorded:remind', 200, false, $once],
        ], array_map(fn (array $request) => [$request['n'], $request['method'], $request['route'], $request['status'], $request['refused'], array_column($request['effects'], 'kind')], $requests));
        $this->assertSame([['exit 0'], ['error']], [$requests[0]['shape'], $requests[3]['shape']]);
        $this->assertSame(['command', [RecordedCommand::class.'::handle']], [$requests[0]['effects'][0]['phase'], $requests[0]['effects'][0]['frames']]);
        $this->assertStringStartsWith(RecordedCommand::PATH.':', (string) $requests[0]['effects'][1]['at']);
    }

    public function test_outside_a_test_the_app_s_own_command_is_recorded_from_laravel_s_console_events()
    {
        Artisan::registerCommand(new RecordedCommand);
        // The app runs as it does on show, where Laravel tells it of each command.
        $this->app['env'] = 'local';
        $recorded = $this->record();
        $output = new BufferedOutput;

        foreach ([['recorded:remind', 0], ['env', 0], ['recorded:remind', 1]] as [$name, $exit]) {
            $input = new ArrayInput([]);
            Event::dispatch(new CommandStarting($name, $input, $output));
            Event::dispatch(new CommandFinished($name, $input, $output, $exit));
        }

        // A command of the framework is not the app's work.
        $this->assertSame([
            ['ARTISAN', 'recorded:remind', 200, ['exit 0']],
            ['ARTISAN', 'recorded:remind', 500, ['exit 1']],
        ], array_map(fn (array $request) => [$request['method'], $request['route'], $request['status'], $request['shape']], $recorded()));
    }

    public function test_a_command_that_an_error_ends_after_it_saved_is_found()
    {
        Artisan::registerCommand(new RecordedCommand);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'mail');

        $this->artisan('recorded:remind')->assertSuccessful();
        rescue(fn () => $this->artisan('recorded:remind')->run(), report: false);

        $requests = $recorded();
        $this->assertSame([[200, false], [500, true]], array_map(fn (array $request) => [$request['status'], $request['refused']], $requests));
        $this->assertSame(1, $requests[1]['fault']);

        $measured = $this->measureFailure($requests, 'mail message', RecordedCommand::PATH);
        $this->assertSame([['saved_then_failed', 'ARTISAN recorded:remind', 'mail message', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('the command ended in an error but had already saved: update users', AppFaults::describe($measured['findings'][0]));
    }

    public function test_a_command_that_ends_with_a_failure_after_it_saved_is_found_too()
    {
        Artisan::registerCommand(new RecordedCommand);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'mail');

        $this->artisan('recorded:remind --careful')->assertSuccessful();
        $this->artisan('recorded:remind --careful')->assertFailed();

        $requests = $recorded();
        $this->assertSame([[200, ['exit 0']], [500, ['exit 1']]], array_map(fn (array $request) => [$request['status'], $request['shape']], $requests));

        $measured = $this->measureFailure($requests, 'mail message', RecordedCommand::PATH);
        $this->assertSame([['saved_then_failed', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['what']], $measured['findings']));
    }

    public function test_a_command_that_catches_a_failure_and_ends_well_hid_it()
    {
        Artisan::registerCommand(new RecordedCommand);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'mail');

        $this->artisan('recorded:remind --hushed')->assertSuccessful();
        $this->artisan('recorded:remind --hushed')->assertSuccessful();

        $requests = $recorded();
        $this->assertSame([false, true], array_map(fn (array $request) => $request['quiet'] ?? false, $requests));

        $measured = $this->measureFailure($requests, 'mail message', RecordedCommand::PATH);
        $this->assertSame([['failure_hidden', 'ARTISAN recorded:remind', 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed']], $measured['findings']));
        $this->assertStringContainsString('the command did nothing new, ended the same as when all worked, and wrote nothing to the log', AppFaults::describe($measured['findings'][0]));
    }

    public function test_a_command_that_catches_a_failure_and_records_it_is_clean()
    {
        Artisan::registerCommand(new RecordedCommand);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'mail');

        $this->artisan('recorded:remind --recorded')->assertSuccessful();
        $this->artisan('recorded:remind --recorded')->assertSuccessful();

        $requests = $recorded();
        $this->assertSame([1, false], [$requests[1]['fault'], $requests[1]['quiet'] ?? false]);

        $measured = $this->measureFailure($requests, 'mail message', RecordedCommand::PATH);
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
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

    public function test_a_job_that_runs_by_itself_is_recorded_the_way_a_request_is()
    {
        $user = User::factory()->create();
        Route::post('/_job/inside', [RecordedApp::class, 'worked']);
        $recorded = $this->record();

        RecordedJob::dispatch();
        // A job a request runs is part of that request.
        $this->post('/_job/inside')->assertNoContent();
        // The test runs this one in place. In use a queue runs it.
        RecordedJob::dispatchSync();
        // A closure the test queues is the test's own code, and a job that delivers a notification is the framework's.
        dispatch(function () {
            User::query()->count();
        });
        $user->notify(new RecordedQueuedNotice);
        // The router's events leave a request open after its answer. The job after it is not part of it.
        $this->withoutMiddleware()->post('/_job/inside')->assertNoContent();
        RecordedJob::dispatch();

        $requests = $recorded();
        $once = [['job', false], ['query', true], ['mail', true], ['query', true]];
        $this->assertSame([
            [0, 'JOB', RecordedJob::class, 200, false, $once],
            [1, 'POST', '/_job/inside', 204, false, $once],
            [2, 'JOB', RecordedJob::class, 200, false, $once],
            [3, 'POST', '/_job/inside', 204, false, $once],
            [4, 'JOB', RecordedJob::class, 200, false, $once],
        ], array_map(fn (array $request) => [$request['n'], $request['method'], $request['route'], $request['status'], $request['refused'], array_map(fn (array $effect) => [$effect['kind'], $effect['job'] ?? false], $request['effects'])], $requests));
        $this->assertSame([['done'], RecordedJob::class, null], [$requests[0]['shape'], $requests[0]['effects'][0]['what'], $requests[0]['effects'][0]['at']]);
        $this->assertSame(['job', [RecordedJob::class.'::handle']], [$requests[0]['effects'][1]['phase'], $requests[0]['effects'][1]['frames']]);
        $this->assertStringStartsWith('tests/Fixtures/RecordedJob.php:', (string) $requests[0]['effects'][1]['at']);
        // Its email is made to fail, and it is run a second time and tried again. It is not held back: no request ran before it.
        $this->assertSame(['send', 'again', 'retry'], array_column(AppFaults::points([$requests[0]], $this->wholeFilePatch('tests/Fixtures/RecordedJob.php')), 'fails'));
    }

    public function test_a_job_that_runs_by_itself_and_is_not_safe_to_run_again_is_found()
    {
        $recorded = $this->recordWithFailure(effect: 0, kind: 'job');

        RecordedJob::dispatch();
        RecordedJob::dispatch();

        $requests = $recorded();
        $this->assertSame([[200, null], [200, 0]], array_map(fn (array $request) => [$request['status'], $request['fault'] ?? null], $requests));
        $this->assertSame(['job', 'query', 'mail', 'query', 'job', 'query', 'mail', 'query'], array_column($requests[1]['effects'], 'kind'));

        $measured = $this->measureFailure($requests, 'job '.RecordedJob::class, 'tests/Fixtures/RecordedJob.php');
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['done_twice', 'JOB '.RecordedJob::class, 'job '.RecordedJob::class, 'insert users, mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_job_that_runs_by_itself_and_is_tried_again_after_its_save_failed_is_found()
    {
        User::factory()->create(['name' => 'Careful']);
        $recorded = $this->recordWithFailure(effect: 3, kind: 'query');

        RecordedCarefulJob::dispatch();
        User::query()->where('name', 'Told')->update(['name' => 'Careful']);
        // The failure of the job reaches the test here. In use it stays on the queue.
        rescue(function () {
            RecordedCarefulJob::dispatch();
        }, report: false);

        $requests = $recorded();
        $this->assertSame([[200, false, ['done']], [500, true, ['error']]], array_map(fn (array $request) => [$request['status'], $request['refused'], $request['shape']], $requests));
        $this->assertSame(['job', 'query', 'mail', 'query', 'job', 'query', 'mail', 'query'], array_column($requests[1]['effects'], 'kind'));
        // A job that did not end well refused no one.
        $this->assertSame([], AppTraces::measure($requests, $this->wholeFilePatch('tests/Fixtures/RecordedCarefulJob.php'))['findings'] ?? null);

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch('tests/Fixtures/RecordedCarefulJob.php'));
        $this->assertSame([['send', 2, 'mail'], ['again', 0, 'job'], ['retry', 3, 'query']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[2]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [2 => $requests], $this->wholeFilePatch('tests/Fixtures/RecordedCarefulJob.php'));
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['sent_again', 'JOB '.RecordedCarefulJob::class, 'job '.RecordedCarefulJob::class, 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_job_that_runs_by_itself_and_catches_a_failure_hid_it()
    {
        $recorded = $this->recordWithFailure(effect: 2, kind: 'mail');

        RecordedHushedJob::dispatch();
        RecordedHushedJob::dispatch();

        $requests = $recorded();
        $this->assertSame([[200, ['done'], null, false], [200, ['done'], 2, true]], array_map(fn (array $request) => [$request['status'], $request['shape'], $request['fault'] ?? null, $request['quiet'] ?? false], $requests));

        $measured = $this->measureFailure($requests, 'mail message', RecordedHushedJob::PATH);
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['failure_hidden', 'JOB '.RecordedHushedJob::class, 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed']], $measured['findings']));
        $this->assertStringContainsString('the job did nothing new, ended the same as when all worked, and wrote nothing to the log', AppFaults::describe($measured['findings'][0]));
        $this->assertStringContainsString('A queue takes a job that ends without an error as done', AppFaults::finding($measured['findings'][0]));
    }

    public function test_a_job_that_runs_by_itself_and_records_or_lets_through_a_failure_is_clean()
    {
        $recorded = $this->recordWithFailure(effect: 2, kind: 'mail');

        RecordedHushedJob::dispatch(recorded: true);
        RecordedHushedJob::dispatch(recorded: true);

        $requests = $recorded();
        $this->assertSame([[200, null, false], [200, 2, false]], array_map(fn (array $request) => [$request['status'], $request['fault'] ?? null, $request['quiet'] ?? false], $requests));
        $measured = $this->measureFailure($requests, 'mail message', RecordedHushedJob::PATH);
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_job_that_runs_by_itself_and_fails_with_its_email_is_clean()
    {
        $recorded = $this->recordWithFailure(effect: 2, kind: 'mail');

        RecordedJob::dispatch();
        // The queue has the failed job: it tries it again, or keeps it as failed.
        rescue(function () {
            RecordedJob::dispatch();
        }, report: false);

        $requests = $recorded();
        $this->assertSame([[200, ['done'], null], [500, ['error'], 2]], array_map(fn (array $request) => [$request['status'], $request['shape'], $request['fault'] ?? null], $requests));
        $measured = $this->measureFailure($requests, 'mail message', 'tests/Fixtures/RecordedJob.php');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_job_tried_again_after_its_email_failed_that_does_not_send_the_second_time_never_sends()
    {
        User::factory()->create(['name' => 'Eager']);
        $recorded = $this->recordWithFailure(effect: 3, kind: 'mail');

        RecordedEagerJob::dispatch();
        DB::table('users')->update(['name' => 'Eager']);
        rescue(function () {
            RecordedEagerJob::dispatch();
        }, report: false);

        $requests = $recorded();
        $this->assertSame([[200, null], [500, 3]], array_map(fn (array $request) => [$request['status'], $request['fault'] ?? null], $requests));
        // The second try asked if the job ran before, saw the mark and stopped.
        $this->assertSame([['job', false], ['query', false], ['query', false], ['mail', false], ['job', true], ['query', false]], array_map(fn (array $effect) => [$effect['kind'], $effect['again'] ?? false], $requests[1]['effects']));

        $measured = $this->measureFailure($requests, 'mail message', RecordedEagerJob::PATH);
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['never_sent', 'JOB '.RecordedEagerJob::class, 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed']], $measured['findings']));
    }

    public function test_a_job_tried_again_after_its_email_failed_that_took_its_mark_back_sends_the_second_time()
    {
        User::factory()->create(['name' => 'Eager']);
        $recorded = $this->recordWithFailure(effect: 3, kind: 'mail');

        RecordedEagerJob::dispatch(takesBack: true);
        DB::table('users')->update(['name' => 'Eager']);
        rescue(function () {
            RecordedEagerJob::dispatch(takesBack: true);
        }, report: false);

        $requests = $recorded();
        $this->assertSame(['job', 'query', 'query', 'mail', 'query', 'job', 'query', 'query', 'mail'], array_column($requests[1]['effects'], 'kind'));

        $measured = $this->measureFailure($requests, 'mail message', RecordedEagerJob::PATH);
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_job_that_sends_to_many_and_is_tried_again_after_one_email_failed_sends_the_first_ones_again()
    {
        // The last email the job sends from the line is the one that fails.
        $recorded = $this->recordWithFailure(effect: 2, kind: 'mail');

        RecordedRoundJob::dispatch();
        rescue(function () {
            RecordedRoundJob::dispatch();
        }, report: false);

        $requests = $recorded();
        $this->assertSame([[200, null], [500, 2]], array_map(fn (array $request) => [$request['status'], $request['fault'] ?? null], $requests));
        $this->assertSame(['job', 'mail', 'mail', 'job', 'mail', 'mail'], array_column($requests[1]['effects'], 'kind'));

        $measured = $this->measureFailure($requests, 'mail message', RecordedRoundJob::PATH);
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['sent_again', 'JOB '.RecordedRoundJob::class, 'mail message', 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('the job started from the top and sent again what it had sent before the failure', AppFaults::describe($measured['findings'][0]));
        $this->assertStringContainsString('Send each email from its own job', AppFaults::finding($measured['findings'][0]));
    }

    public function test_a_job_that_dispatches_one_job_for_each_person_has_no_email_of_its_own_to_fail()
    {
        $recorded = $this->record();

        RecordedRoundJob::dispatch(apart: true);

        $requests = $recorded();
        $this->assertSame([['job', false], ['job', true], ['mail', true], ['job', true], ['mail', true]], array_map(fn (array $effect) => [$effect['kind'], $effect['job'] ?? false], $requests[0]['effects']));
        // In use each of those jobs runs by itself: its failure is not the failure of the job that dispatched it.
        $this->assertSame(['again'], array_column(AppFaults::points($requests, $this->wholeFilePatch(RecordedRoundJob::PATH)), 'fails'));
    }

    public function test_a_request_that_sends_to_many_and_stops_at_the_first_failure_leaves_the_rest_unsent()
    {
        Route::post('/_failing/round', [RecordedApp::class, 'round']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');

        $this->post('/_failing/round')->assertNoContent();
        $this->post('/_failing/round')->assertServerError();

        $requests = $recorded();
        $this->assertSame([[204, ['mail', 'mail']], [500, ['mail']]], array_map(fn (array $request) => [$request['status'], array_column($request['effects'], 'kind')], $requests));

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['rest_not_sent', 'POST /_failing/round', 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed']], $measured['findings']));
        $this->assertStringContainsString('one failure stopped the rest', AppFaults::describe($measured['findings'][0]));
    }

    public function test_a_request_that_sends_to_many_and_goes_on_after_a_failure_it_recorded_is_clean()
    {
        Route::post('/_failing/round', [RecordedApp::class, 'round']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');

        $this->post('/_failing/round?careful=1')->assertNoContent();
        $this->post('/_failing/round?careful=1')->assertNoContent();

        $requests = $recorded();
        $this->assertSame([[null, ['mail', 'mail']], [0, ['mail', 'mail']]], array_map(fn (array $request) => [$request['fault'] ?? null, array_column($request['effects'], 'kind')], $requests));

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_job_of_a_request_that_catches_a_failure_hid_it()
    {
        $user = User::factory()->create();
        Route::post('/_failing/hushed-later/{user}', [RecordedApp::class, 'hushedLater']);
        $recorded = $this->recordWithFailure(effect: 3, kind: 'mail');

        $this->post("/_failing/hushed-later/{$user->id}")->assertNoContent();
        $this->post("/_failing/hushed-later/{$user->id}")->assertNoContent();

        $requests = $recorded();
        $this->assertSame([[204, null, false], [204, 3, true]], array_map(fn (array $request) => [$request['status'], $request['fault'] ?? null, $request['quiet'] ?? false], $requests));
        $this->assertSame([['query', false], ['job', false], ['query', true], ['mail', true]], array_map(fn (array $effect) => [$effect['kind'], $effect['job'] ?? false], $requests[1]['effects']));

        $measured = $this->measureFailure($requests, 'mail message', RecordedHushedJob::PATH);
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['failure_hidden', 'POST /_failing/hushed-later/{user}', 'mail message', true]], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['job'] ?? false], $measured['findings']));
        // The coder is told about the job, not about a person who sees an answer.
        $this->assertStringContainsString('in a job the request queued, the job caught the failure and hid it', AppFaults::finding($measured['findings'][0]));
        $this->assertStringEndsWith('A queue takes a job that ends without an error as done, and does not try it again. Let the job fail, or record the failure with report().', AppFaults::finding($measured['findings'][0]));
    }

    public function test_a_job_of_a_request_that_records_a_failure_or_lets_it_through_is_clean()
    {
        $user = User::factory()->create();
        Route::post('/_failing/hushed-later/{user}', [RecordedApp::class, 'hushedLater']);
        Route::post('/_failing/worked-later/{user}', [RecordedApp::class, 'workedLater']);
        $recorded = $this->recordWithFailure(effect: 3, kind: 'mail');

        $this->post("/_failing/hushed-later/{$user->id}?recorded=1")->assertNoContent();
        $this->post("/_failing/hushed-later/{$user->id}?recorded=1")->assertNoContent();

        $requests = $recorded();
        $this->assertSame([3, false], [$requests[1]['fault'] ?? null, $requests[1]['quiet'] ?? false]);
        $measured = $this->measureFailure($requests, 'mail message', RecordedHushedJob::PATH);
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);

        // The failure of the job reaches the request here. In use it stays on the queue, after the answer,
        // so what the request saved before it is not held against the request.
        $this->app->forgetInstance(Recorder::class);
        File::delete("{$this->directory}/trace.jsonl");
        $let = [
            ['test' => null, 'method' => 'POST', 'route' => '/orders', 'status' => 204, 'refused' => false, 'blind' => [], 'cut' => false, 'n' => 0, 'shape' => [], 'effects' => [
                ['kind' => 'query', 'open' => 0, 'sql' => 'update "users" set "name" = ?', 'at' => 'app/Http/Controllers/OrderController.php:3'],
                ['kind' => 'job', 'open' => 0, 'what' => 'App\\Jobs\\SendReceipt', 'at' => 'app/Http/Controllers/OrderController.php:4'],
                ['kind' => 'mail', 'open' => 0, 'what' => 'message', 'at' => 'app/Jobs/SendReceipt.php:3', 'job' => true],
            ]],
        ];
        $let[0]['test'] = 'Tests\\Feature\\OrderTest::test_order';
        $patch = "diff --git a/app/Jobs/SendReceipt.php b/app/Jobs/SendReceipt.php\n--- /dev/null\n+++ b/app/Jobs/SendReceipt.php\n@@ -0,0 +1,5 @@\n".str_repeat("+//\n", 5);
        $points = AppFaults::points($let, $patch);
        $this->assertSame([['again', 1, 'job'], ['later', 1, 'later'], ['send', 2, 'mail']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $failed = [[...$let[0], 'status' => 500, 'refused' => true, 'fault' => 2, 'shape' => []]];
        $this->assertSame([1, []], array_values(array_intersect_key((array) AppFaults::measure($points, [2 => $failed], $patch), ['run' => 1, 'findings' => 1])));
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
        // It still waits on a queue: a worker runs what the notification says.
        $this->assertSame(['later'], array_column(AppFaults::points([$told], $this->wholeFilePatch()), 'fails'));
        // The job changes a row after it sends: that save is a place of its own.
        $this->assertSame(['again', 'retry', 'later', 'send'], array_column(AppFaults::points([$worked], $this->wholeFilePatch()), 'fails'));
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
        $this->assertSame([['again', 0, 'job'], ['retry', 3, 'query'], ['later', 0, 'later'], ['send', 2, 'mail']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
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

    public function test_a_queued_notification_held_back_until_the_response_keeps_the_line_that_dispatched_it()
    {
        $user = User::factory()->create();
        Route::post('/_failing/told/{user}', [RecordedApp::class, 'told'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 1, kind: 'later');

        $this->post("/_failing/told/{$user->id}")->assertNoContent();
        $this->post("/_failing/told/{$user->id}")->assertNoContent();

        $requests = $recorded();
        $this->assertSame([null, 1], [$requests[0]['fault'] ?? null, $requests[1]['fault'] ?? null]);

        // The framework's job has no code of the app in it. Its email came
        // from the line that dispatched it, in both runs.
        $mails = array_map(fn (array $request) => array_values(array_map(fn (array $effect) => $effect['at'], array_filter($request['effects'], fn (array $effect) => $effect['kind'] === 'mail'))), $requests);
        $this->assertSame([[$requests[0]['effects'][1]['at']], [$requests[0]['effects'][1]['at']]], $mails);
        $this->assertNotNull($mails[0][0]);

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $this->assertSame([['later', 1, 'later']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[0]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [0 => $requests], $this->wholeFilePatch());
        $this->assertSame([1, 0, []], [$measured['run'], $measured['missed'], $measured['findings']]);
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
        $this->assertSame([['send', 5, 'mail'], ['again', 0, 'job'], ['retry', 3, 'query'], ['later', 0, 'later'], ['send', 2, 'mail']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[3]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [3 => $requests], $this->wholeFilePatch());
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['needs_job_done', 'POST /_failing/waited', 'job '.RecordedCarefulJob::class, 'missing mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_job_that_takes_the_person_from_the_request_sends_nothing_when_a_worker_runs_it()
    {
        [$requests, $measured] = $this->thankOnAQueue(['from' => 'person']);

        $this->assertSame([['job', 'mail'], ['job'], ['job', 'mail']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['job_needs_request', 'POST /_failing/thanked', 'job '.RecordedPersonalJob::class, 'missing mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_job_that_takes_what_the_person_sent_from_the_request_sends_nothing_when_a_worker_runs_it()
    {
        [, $measured] = $this->thankOnAQueue(['from' => 'sent', 'email' => 'sent@example.com']);

        $this->assertSame([1, 0, ['job_needs_request']], [$measured['run'], $measured['missed'], array_column($measured['findings'], 'kind')]);
    }

    public function test_a_job_that_reads_the_session_sends_nothing_when_a_worker_runs_it()
    {
        $this->withSession(['email' => 'session@example.com']);

        [, $measured] = $this->thankOnAQueue(['from' => 'session']);

        $this->assertSame([1, 0, ['job_needs_request']], [$measured['run'], $measured['missed'], array_column($measured['findings'], 'kind')]);
        $this->assertSame('session@example.com', session('email'));
    }

    public function test_a_job_about_a_row_the_request_deletes_after_it_queued_the_job_sends_nothing_when_a_worker_runs_it()
    {
        $users = User::factory()->count(2)->create();
        Route::post('/_failing/dropped/{user}', [RecordedApp::class, 'dropped'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 1, kind: 'later');

        $this->post("/_failing/dropped/{$users[0]->id}")->assertNoContent();
        $this->post("/_failing/dropped/{$users[1]->id}")->assertNoContent();

        $requests = $recorded();
        $did = fn (array $request) => array_map(fn (array $effect) => [AppTraces::verb($effect['sql'] ?? '') ?: $effect['kind'], $effect['job'] ?? false], $request['effects']);

        $this->assertSame([['select', false], ['job', false], ['select', true], ['mail', true], ['delete', false]], $did($requests[0]));
        // The worker could not load the person: the row was gone when the job ran.
        $this->assertSame([['select', false], ['job', false], ['delete', false], ['select', true]], array_slice($did($requests[1]), 0, 4));
        $this->assertNotContains(['mail', true], $did($requests[1]));

        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch());
        $this->assertSame([['again', 1, 'job'], ['later', 1, 'later'], ['save', 4, 'query'], ['send', 3, 'mail']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
        $points[1]['fault']['request'] = 1;

        $measured = (array) AppFaults::measure($points, [1 => $requests], $this->wholeFilePatch());
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['job_needs_request', 'POST /_failing/dropped/{user}', 'job '.RecordedPersonalJob::class, 'missing mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_job_that_was_given_what_it_needs_does_the_same_when_a_worker_runs_it()
    {
        [$requests, $measured] = $this->thankOnAQueue(['from' => 'given']);

        $this->assertSame([['job', 'mail'], ['job', 'mail'], ['job', 'mail']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame([1, 0, []], [$measured['run'], $measured['missed'], $measured['findings']]);
    }

    public function test_a_job_the_app_sends_to_the_sync_queue_by_name_is_not_held_back()
    {
        Route::post('/_failing/ran', [RecordedApp::class, 'ran']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'later');

        $this->post('/_failing/ran')->assertNoContent();
        $this->post('/_failing/ran')->assertNoContent();

        $requests = $recorded();
        $this->assertSame(['query', 'mail', 'query'], array_column($requests[1]['effects'], 'kind'));
        $this->assertSame([[null, $requests[0]['effects']]], [[$requests[1]['fault'] ?? null, $requests[1]['effects']]]);
    }

    public function test_a_job_the_app_runs_in_place_by_name_is_part_of_the_request()
    {
        Route::post('/_recorded/both', [RecordedApp::class, 'both']);
        $recorded = $this->record();

        $this->post('/_recorded/both')->assertNoContent();

        [$request] = $recorded();
        $did = array_map(fn (array $effect) => [AppTraces::verb($effect['sql'] ?? '') ?: $effect['kind'], $effect['job'] ?? false], $request['effects']);

        // Only the job the queue takes is marked. The one before it ran where the app's code is.
        $this->assertSame([['insert', false], ['mail', false], ['update', false], ['job', false], ['insert', true], ['mail', true], ['update', true]], $did);
        $this->assertStringStartsWith('tests/Fixtures/RecordedJob.php:', $request['effects'][1]['at']);

        // In use that job runs once and now. It is no place for a second run or a wait: its email and its last save are places of the request.
        $points = AppFaults::points([$request], $this->wholeFilePatch());
        $this->assertSame([['again', 3, 'job'], ['retry', 6, 'query'], ['later', 3, 'later'], ['send', 1, 'mail'], ['save', 2, 'query']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));
    }

    public function test_a_listener_that_waits_on_a_queue_is_read_as_a_job_of_the_app()
    {
        Route::post('/_failing/ordered', [RecordedApp::class, 'ordered']);
        Event::listen(RecordedEvent::class, RecordedQueuedListener::class);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'mail');

        $this->post('/_failing/ordered')->assertNoContent();
        $this->post('/_failing/ordered')->assertNoContent();

        $requests = $recorded();
        // The job has the listener's name, and the app wrote what it does.
        $this->assertSame([['job', RecordedQueuedListener::class, false, false], ['mail', 'message', false, true]], array_map(fn (array $effect) => [$effect['kind'], $effect['what'], $effect['delivers'] ?? false, $effect['job'] ?? false], $requests[0]['effects']));
        $this->assertStringStartsWith(RecordedQueuedListener::PATH.':', $requests[0]['effects'][1]['at']);
        $this->assertSame([[204, null], [204, 1]], array_map(fn (array $request) => [$request['status'], $request['fault'] ?? null], $requests));

        // It has the places of a job: run twice, run after the response, and its email fails.
        $points = AppFaults::points([$requests[0]], $this->wholeFilePatch(RecordedQueuedListener::PATH));
        $this->assertSame([['again', 0, 'job'], ['later', 0, 'later'], ['send', 1, 'mail']], array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind']], $points));

        $measured = $this->measureFailure($requests, 'mail message', RecordedQueuedListener::PATH);
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['failure_hidden', 'POST /_failing/ordered', 'mail message', true]], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['job'] ?? false], $measured['findings']));
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

    public function test_an_email_failure_the_app_catches_and_tells_no_one_about_is_found()
    {
        Route::post('/_hidden/receipt', [RecordedApp::class, 'hushed'])->middleware('web');
        Route::get('/_hidden/receipt', [RecordedApp::class, 'quiet']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');

        $this->post('/_hidden/receipt')->assertRedirect('/_hidden/receipt')->assertSessionHas('status');
        $this->post('/_hidden/receipt')->assertRedirect('/_hidden/receipt')->assertSessionHas('status');

        $requests = $recorded();
        $this->assertSame([false, true], array_map(fn (array $request) => $request['quiet'] ?? false, $requests));
        $this->assertSame([false, false], array_map(fn (array $request) => $request['dark'] ?? false, $requests));
        // The answer is kept by its names: the route it leads to and what it tells the person, not the words.
        $this->assertSame([['flash status', 'to /_hidden/receipt'], ['flash status', 'to /_hidden/receipt']], array_column($requests, 'shape'));
        $this->assertStringNotContainsString('on its way', File::get("{$this->directory}/trace.jsonl"));

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['failure_hidden', 'POST /_hidden/receipt', 'mail message', 'mail message']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_an_email_failure_the_app_catches_and_records_is_clean()
    {
        Route::post('/_hidden/receipt', [RecordedApp::class, 'hushed'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');

        $this->post('/_hidden/receipt?recorded=1')->assertSessionHas('status');
        $this->post('/_hidden/receipt?recorded=1')->assertSessionHas('status');

        $requests = $recorded();
        $this->assertSame(0, $requests[1]['fault']);
        // report() wrote to the log after the failure.
        $this->assertSame([false, false], array_map(fn (array $request) => $request['quiet'] ?? false, $requests));

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_an_email_failure_the_app_catches_and_tells_the_person_about_is_clean()
    {
        Route::post('/_hidden/receipt', [RecordedApp::class, 'hushed'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');

        $this->post('/_hidden/receipt?told=1')->assertSessionHas('status');
        $this->post('/_hidden/receipt?told=1')->assertSessionHas('problem');

        $requests = $recorded();
        // No route takes the address in this test, and the person was told something else.
        $this->assertSame([['flash status', 'to ?'], ['flash problem', 'to ?']], array_column($requests, 'shape'));
        $this->assertTrue($requests[1]['quiet']);

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_file_write_made_to_fail_shows_an_app_that_carries_on_without_asking_the_disk()
    {
        Route::post('/_stored/note', [RecordedApp::class, 'stored'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 1, kind: 'file');
        // A disk made after the recorder started tells it of each write.
        Storage::fake('recorded');

        $this->post('/_stored/note')->assertSessionHas('status');
        Storage::disk('recorded')->assertExists('notes/note.txt');
        Storage::disk('recorded')->delete('notes/note.txt');
        // The disk gives false and throws nothing, so the request answers as usual.
        $this->post('/_stored/note')->assertSessionHas('status');
        Storage::disk('recorded')->assertMissing('notes/note.txt');

        $requests = $recorded();
        $this->assertSame([['query', 'file'], ['query', 'file']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));
        $this->assertSame(['write', RecordedApp::PATH], [$requests[0]['effects'][1]['what'], strstr((string) $requests[0]['effects'][1]['at'], ':', true)]);
        $this->assertSame([false, true], array_map(fn (array $request) => $request['quiet'] ?? false, $requests));
        // Only that a file was written is kept, not its name.
        $this->assertStringNotContainsString('note.txt', File::get("{$this->directory}/trace.jsonl"));

        $measured = $this->measureFailure($requests, 'file write');
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['failure_hidden', 'POST /_stored/note', 'file write', 'file write']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('the app went on as if the file was stored', AppFaults::finding($measured['findings'][0]));
    }

    public function test_an_app_that_asks_the_disk_and_tells_the_person_the_file_was_not_stored_is_clean()
    {
        Route::post('/_stored/note', [RecordedApp::class, 'stored'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 1, kind: 'file');
        Storage::fake('recorded');

        $this->post('/_stored/note?careful=1')->assertSessionHas('status');
        $this->post('/_stored/note?careful=1')->assertSessionHas('problem');

        $measured = $this->measureFailure($recorded(), 'file write');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_disk_that_throws_ends_the_request_in_an_error_and_what_it_saved_stays()
    {
        Route::post('/_stored/note', [RecordedApp::class, 'stored'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 1, kind: 'file');
        Storage::fake('recorded', ['throw' => true]);

        $this->post('/_stored/note')->assertSessionHas('status');
        $this->post('/_stored/note')->assertStatus(500);

        $measured = $this->measureFailure($recorded(), 'file write');
        $this->assertSame([['saved_then_failed', 'file write', 'update users']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('Store the file before the save', AppFaults::finding($measured['findings'][0]));
    }

    public function test_a_disk_the_app_made_before_the_recorder_started_still_works_and_is_not_seen()
    {
        Route::post('/_stored/note', [RecordedApp::class, 'stored'])->middleware('web');
        Storage::fake('recorded');
        $recorded = $this->record();

        $this->post('/_stored/note')->assertSessionHas('status');

        Storage::disk('recorded')->assertExists('notes/note.txt');
        $this->assertSame(['query'], array_column($recorded()[0]['effects'], 'kind'));
    }

    public function test_a_file_deleted_before_a_save_that_fails_is_gone_and_what_was_kept_still_names_it()
    {
        Route::post('/_stored/removed', [RecordedApp::class, 'removed']);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'query');
        Storage::fake('recorded');

        $this->post('/_stored/removed')->assertNoContent();
        Storage::disk('recorded')->put('notes/note.txt', 'A note');
        $this->post('/_stored/removed')->assertStatus(500);
        // The row was not deleted, and its file is gone.
        Storage::disk('recorded')->assertMissing('notes/note.txt');

        $requests = $recorded();
        $this->assertSame([['file', 'delete'], ['query', null]], array_map(fn (array $effect) => [$effect['kind'], $effect['what'] ?? null], $requests[0]['effects']));
        $this->assertStringNotContainsString('note.txt', File::get("{$this->directory}/trace.jsonl"));

        $measured = $this->measureFailure($requests, 'delete users');
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['file_gone', 'POST /_stored/removed', 'delete users', 'file delete']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
    }

    public function test_a_file_deleted_after_the_save_is_no_place()
    {
        Route::post('/_stored/removed', [RecordedApp::class, 'removed']);
        $recorded = $this->record();
        Storage::fake('recorded');

        $this->post('/_stored/removed?careful=1')->assertNoContent();

        $requests = $recorded();
        $this->assertSame(['query', 'file'], array_column($requests[0]['effects'], 'kind'));
        // The delete of a file is not made to fail, and no save comes after it.
        $this->assertSame([], AppFaults::points($requests, $this->wholeFilePatch()));
    }

    public function test_an_old_file_deleted_before_a_new_file_that_is_not_stored_is_gone_and_the_save_never_happens()
    {
        Route::post('/_stored/replaced', [RecordedApp::class, 'replaced'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 1, kind: 'file');
        Storage::fake('recorded');

        Storage::disk('recorded')->put('notes/old.txt', 'An old note');
        $this->post('/_stored/replaced')->assertSessionHas('status');
        Storage::disk('recorded')->put('notes/old.txt', 'An old note');
        // The app tells the person, but the old file is gone and no row names the new one.
        $this->post('/_stored/replaced')->assertSessionHas('problem');
        Storage::disk('recorded')->assertMissing('notes/old.txt');

        $requests = $recorded();
        $this->assertSame([['file', 'file', 'query'], ['file', 'file']], array_map(fn (array $request) => array_column($request['effects'], 'kind'), $requests));

        $measured = $this->measureFailure($requests, 'file write');
        $this->assertSame([1, 0], [$measured['run'], $measured['missed']]);
        $this->assertSame([['file_gone', 'POST /_stored/replaced', 'file write', 'file delete']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('the request did not make a save it makes when all works, but had already deleted a file', AppFaults::finding($measured['findings'][0]));
    }

    public function test_an_app_that_deletes_the_old_file_last_is_clean_when_the_new_file_is_not_stored()
    {
        Route::post('/_stored/replaced', [RecordedApp::class, 'replaced'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 0, kind: 'file');
        Storage::fake('recorded');

        Storage::disk('recorded')->put('notes/old.txt', 'An old note');
        $this->post('/_stored/replaced?careful=1')->assertSessionHas('status');
        Storage::disk('recorded')->put('notes/old.txt', 'An old note');
        $this->post('/_stored/replaced?careful=1')->assertSessionHas('problem');
        // The row names the old file, and the old file is still there.
        Storage::disk('recorded')->assertExists('notes/old.txt');

        $measured = $this->measureFailure($recorded(), 'file write');
        $this->assertSame([1, 0, []], [$measured['run'], $measured['missed'], $measured['findings']]);
    }

    public function test_a_move_made_to_fail_shows_an_app_that_carries_on_as_if_the_file_was_moved()
    {
        Route::post('/_stored/moved', [RecordedApp::class, 'moved']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'file');
        Storage::fake('recorded');

        Storage::disk('recorded')->put('notes/draft.txt', 'A note');
        $this->post('/_stored/moved')->assertNoContent();
        Storage::disk('recorded')->assertExists('notes/kept.txt');
        Storage::disk('recorded')->move('notes/kept.txt', 'notes/draft.txt');
        // The disk gives false and throws nothing, so the request saves the new place and answers as usual.
        $this->post('/_stored/moved')->assertNoContent();
        Storage::disk('recorded')->assertMissing('notes/kept.txt');

        $requests = $recorded();
        $this->assertSame([[['file', 'move'], ['query', null]], [['file', 'move'], ['query', null]]], array_map(fn (array $request) => array_map(fn (array $effect) => [$effect['kind'], $effect['what'] ?? null], $request['effects']), $requests));
        $this->assertStringNotContainsString('draft.txt', File::get("{$this->directory}/trace.jsonl"));

        $measured = $this->measureFailure($requests, 'file move');
        $this->assertSame([['failure_hidden', 'POST /_stored/moved', 'file move', 'file move']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('the app went on as if the file was moved', AppFaults::finding($measured['findings'][0]));
    }

    public function test_a_file_moved_before_a_save_that_fails_is_not_where_what_was_kept_says()
    {
        Route::post('/_stored/moved', [RecordedApp::class, 'moved']);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'query');
        Storage::fake('recorded');

        Storage::disk('recorded')->put('notes/draft.txt', 'A note');
        $this->post('/_stored/moved')->assertNoContent();
        Storage::disk('recorded')->move('notes/kept.txt', 'notes/draft.txt');
        $this->post('/_stored/moved')->assertStatus(500);
        // The new place was not saved, and the file is there.
        Storage::disk('recorded')->assertMissing('notes/draft.txt');

        $measured = $this->measureFailure($recorded(), 'update users');
        $this->assertSame([['file_gone', 'POST /_stored/moved', 'update users', 'file move']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
        $this->assertStringContainsString('the request had already moved a file', AppFaults::finding($measured['findings'][0]));
    }

    public function test_an_app_that_saves_first_in_a_transaction_and_throws_when_the_move_fails_is_clean()
    {
        Route::post('/_stored/moved', [RecordedApp::class, 'moved']);
        $recorded = $this->recordWithFailure(effect: 2, kind: 'file');
        Storage::fake('recorded');

        Storage::disk('recorded')->put('notes/draft.txt', 'A note');
        $this->post('/_stored/moved?careful=1')->assertNoContent();
        Storage::disk('recorded')->move('notes/kept.txt', 'notes/draft.txt');
        $this->post('/_stored/moved?careful=1')->assertStatus(500);

        $requests = $recorded();
        $this->assertSame(['begin', 'query', 'file', 'rollback'], array_column($requests[1]['effects'], 'kind'));

        $measured = $this->measureFailure($requests, 'file move');
        $this->assertSame([1, 0, []], [$measured['run'], $measured['missed'], $measured['findings']]);
    }

    public function test_a_copy_is_a_write_and_is_made_to_fail_the_same_way()
    {
        Route::post('/_stored/copied', [RecordedApp::class, 'copied']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'file');
        Storage::fake('recorded');

        Storage::disk('recorded')->put('notes/note.txt', 'A note');
        $this->post('/_stored/copied')->assertExactJson(['copied' => true]);
        Storage::disk('recorded')->delete('notes/copy.txt');
        $this->post('/_stored/copied')->assertExactJson(['copied' => false]);
        Storage::disk('recorded')->assertMissing('notes/copy.txt');

        $requests = $recorded();
        $this->assertSame([[['file', 'write']], [['file', 'write']]], array_map(fn (array $request) => array_map(fn (array $effect) => [$effect['kind'], $effect['what'] ?? null], $request['effects']), $requests));
        $this->assertSame(0, $requests[1]['fault'] ?? null);
    }

    public function test_what_a_json_answer_tells_the_person_is_read_by_its_names_at_every_depth()
    {
        Route::post('/_hidden/screen', [RecordedApp::class, 'screened']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');

        $this->post('/_hidden/screen?told=1')->assertOk();
        $this->post('/_hidden/screen?told=1')->assertOk();
        $this->post('/_hidden/screen')->assertOk();

        $requests = $recorded();
        $names = ['json components', 'json components.effects', 'json components.effects.html', 'json components.snapshot', 'json components.snapshot.data', 'json components.snapshot.data.email', 'json components.snapshot.data.rows', 'json components.snapshot.data.rows.name', 'json components.snapshot.memo', 'json components.snapshot.memo.errors'];
        // Text that holds JSON is read too, and the id of a row is a value: it is left out.
        $this->assertSame($names, $requests[0]['shape']);
        // The field named as wrong is the only thing that tells the two answers apart.
        $this->assertSame([...$names, 'json components.snapshot.memo.errors.email'], $requests[1]['shape']);
        $this->assertTrue($requests[1]['quiet']);
        $this->assertStringNotContainsString('First', File::get("{$this->directory}/trace.jsonl"));

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_json_answer_with_many_names_keeps_some_and_one_mark_for_all()
    {
        Route::get('/_hidden/many', fn () => response()->json(array_fill_keys(array_map(fn (int $at) => "name_{$at}", range(1, 50)), ['inner' => 1])));
        Route::get('/_hidden/many-more', fn () => response()->json([...array_fill_keys(array_map(fn (int $at) => "name_{$at}", range(1, 50)), ['inner' => 1]), 'zz_errors' => 1]));
        $recorded = $this->record();

        $this->get('/_hidden/many')->assertOk();
        $this->get('/_hidden/many')->assertOk();
        $this->get('/_hidden/many-more')->assertOk();

        $shapes = array_column($recorded(), 'shape');
        $this->assertCount(41, $shapes[0]);
        $this->assertMatchesRegularExpression('/^json more [0-9a-f]{12}$/', $shapes[0][0]);
        $this->assertSame($shapes[0], $shapes[1]);
        // A name past the ones kept still makes the shape another one.
        $this->assertSame(array_slice($shapes[0], 1), array_slice($shapes[2], 1));
        $this->assertNotSame($shapes[0][0], $shapes[2][0]);
    }

    public function test_an_inertia_page_is_told_apart_by_its_component_in_a_view_and_as_json()
    {
        Route::post('/_hidden/page', [RecordedApp::class, 'paged']);
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');
        $this->withoutVite();

        $this->post('/_hidden/page?told=1')->assertOk();
        $this->post('/_hidden/page?told=1')->assertOk();
        // The failure is caused in the second request only.
        $this->post('/_hidden/page?told=1', [], ['X-Inertia' => 'true'])->assertOk();

        $requests = $recorded();
        $this->assertSame(['page Receipt/Sent', 'view app', 'with page', 'with page.component', 'with page.props', 'with page.props.receipt', 'with page.props.receipt.lines', 'with page.props.receipt.lines.name', 'with page.props.receipt.number', 'with page.url', 'with page.version'], $requests[0]['shape']);
        $this->assertContains('page Receipt/NotSent', $requests[1]['shape']);
        $this->assertSame(array_diff($requests[0]['shape'], ['page Receipt/Sent']), array_diff($requests[1]['shape'], ['page Receipt/NotSent']));
        $this->assertSame(['json component', 'json props', 'json props.receipt', 'json props.receipt.lines', 'json props.receipt.lines.name', 'json props.receipt.number', 'json url', 'json version', 'page Receipt/Sent'], $requests[2]['shape']);
        $this->assertTrue($requests[1]['quiet']);

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_caught_failure_is_still_judged_when_the_test_turned_off_the_apps_handling_of_errors()
    {
        Route::post('/_hidden/receipt', [RecordedApp::class, 'hushed'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');
        // The test's handler drops each report(). The recorder still sees it.
        $this->withoutExceptionHandling();

        $this->post('/_hidden/receipt')->assertSessionHas('status');
        $this->post('/_hidden/receipt')->assertSessionHas('status');
        $hidden = $recorded();

        $this->assertSame([false, true], array_map(fn (array $request) => $request['quiet'] ?? false, $hidden));
        $this->assertSame([false, false], array_map(fn (array $request) => $request['dark'] ?? false, $hidden));
        $this->assertSame(['failure_hidden'], array_column($this->measureFailure($hidden, 'mail message')['findings'], 'kind'));
        // The test can still turn the handling back on: it gets the app's own handler.
        $this->withExceptionHandling();
        $this->assertSame($this->app->make(ExceptionHandler::class)::class, Handler::class);
    }

    public function test_an_app_that_records_a_caught_failure_is_clean_with_the_apps_handling_of_errors_off()
    {
        Route::post('/_hidden/receipt', [RecordedApp::class, 'hushed'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');
        $this->withoutExceptionHandling();

        $this->post('/_hidden/receipt?recorded=1')->assertSessionHas('status');
        $this->post('/_hidden/receipt?recorded=1')->assertSessionHas('status');

        $requests = $recorded();
        $this->assertSame([0, false, false], [$requests[1]['fault'], $requests[1]['quiet'] ?? false, $requests[1]['dark'] ?? false]);
        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_nothing_is_said_about_a_caught_failure_when_the_test_put_a_mock_in_place_of_the_log()
    {
        Route::post('/_hidden/receipt', [RecordedApp::class, 'hushed'])->middleware('web');
        $recorded = $this->recordWithFailure(effect: 0, kind: 'mail');
        // The app writes the failure to the log, but the mock takes it: an empty log says nothing.
        Log::spy();

        $this->post('/_hidden/receipt?logged=1')->assertSessionHas('status');
        $this->post('/_hidden/receipt?logged=1')->assertSessionHas('status');

        $requests = $recorded();
        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(0, $requests[1]['fault']);
        $this->assertSame([false, false], array_map(fn (array $request) => $request['quiet'] ?? false, $requests));
        // The trace says so, and another test for the same place is taken when there is one.
        $this->assertSame([true, true], array_map(fn (array $request) => $request['dark'] ?? false, $requests));

        $measured = $this->measureFailure($requests, 'mail message');
        $this->assertSame([1, []], [$measured['run'], $measured['findings']]);
    }

    public function test_a_save_failure_the_app_catches_and_tells_no_one_about_is_found()
    {
        Route::post('/_hidden/saved', [RecordedApp::class, 'swallowed']);
        $recorded = $this->recordWithFailure(effect: 1, kind: 'query');

        $this->post('/_hidden/saved')->assertNoContent();
        $this->post('/_hidden/saved')->assertNoContent();

        $requests = $recorded();
        $this->assertSame(['begin 1', 'query 1', 'rollback 0'], array_map(fn (array $effect) => $effect['kind'].' '.$effect['open'], $requests[1]['effects']));
        $this->assertSame([[], []], array_column($requests, 'shape'));
        $this->assertTrue($requests[1]['quiet']);
        // The person was told it worked, and only the first request's user is there.
        $this->assertSame(1, User::query()->count());

        $measured = $this->measureFailure($requests, 'insert users');
        $this->assertSame([['failure_hidden', 'POST /_hidden/saved', 'insert users', 'insert users']], array_map(fn (array $finding) => [$finding['kind'], $finding['route'], $finding['failed'], $finding['what']], $measured['findings']));
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
