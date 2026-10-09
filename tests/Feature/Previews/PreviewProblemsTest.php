<?php

namespace Tests\Feature\Previews;

use App\Actions\Context\RecordDecision;
use App\Actions\Features\ListDecisions;
use App\Actions\Previews\ReadPreviewProblems;
use App\Actions\Projects\CreateProject;
use App\Actions\Runs\StartRun;
use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\SpendPause;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use ErrorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;
use TraceRecorder\Recorder;

class PreviewProblemsTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the fix is not what these tests are about.
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));
        $this->driver = $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $this->workspace->id]);
    }

    public function test_the_owner_sees_the_problems_the_app_ran_into_in_plain_words_the_same_one_counted()
    {
        $this->writeLog(function () {
            foreach ([4, 7] as $plan) {
                // The same fault, met on two pages, with a different number.
                collect([$plan])->each(fn (int $id) => report(new ErrorException("Undefined array key {$id}")));
            }
            Log::error('Payment gateway timed out', ['order' => 12]);
            Log::warning('Slow page');
            Log::info('Signed in');
        });

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('problems')->reloadOnly('problems', fn (Assert $page) => $page
                ->count('problems', 2)
                ->where('problems.0.words', 'Your app noted a problem.')
                ->where('problems.0.message', 'Payment gateway timed out')
                ->where('problems.0.class', null)
                ->where('problems.0.count', 1)
                ->where('problems.1.words', 'The app used something that was not there.')
                ->where('problems.1.class', 'ErrorException')
                ->where('problems.1.message', 'Undefined array key 7')
                ->where('problems.1.count', 2)
                // Shown from the app's folder, and only the app's own code.
                ->where('problems.1.place', fn (string $place) => str_starts_with($place, 'tests/Feature/Previews/PreviewProblemsTest.php:'))
                ->where('problems.1.trace', fn ($trace) => collect($trace)->every(fn (string $place) => ! str_contains($place, 'vendor/') && ! str_starts_with($place, '/')))
                ->where('problems.1.last_at', fn (?string $time) => $time !== null)));
    }

    public function test_code_the_builder_loads_into_the_app_on_show_is_not_shown_as_the_apps_own()
    {
        $this->driver->files["{$this->workspace->driver_id}:storage/logs/laravel.log"] = implode("\n", [
            '[2026-10-02 21:26:00] local.ERROR: Vite manifest not found at: /workspaces/w1/public/build/manifest.json {"exception":"[object] (Illuminate\\View\\ViewException(code: 0): Vite manifest not found at: /workspaces/w1/public/build/manifest.json at /workspaces/w1/vendor/laravel/framework/src/Illuminate/Foundation/Vite.php:974)',
            '[stacktrace]',
            '#0 /workspaces/w1/app/Http/Middleware/BlockBots.php(41): Illuminate\\Foundation\\Vite->manifest()',
            '#1 /opt/trace-recorder/src/Middleware.php(25): App\\Http\\Middleware\\BlockBots->handle()',
            '#2 /workspaces/w1/public/index.php(15): Illuminate\\Foundation\\Http\\Kernel->handle()',
            '#3 {main}',
            '"} ',
            '',
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('problems', fn (Assert $page) => $page
                ->count('problems', 1)
                ->where('problems.0.place', 'vendor/laravel/framework/src/Illuminate/Foundation/Vite.php:974')
                ->where('problems.0.trace', ['app/Http/Middleware/BlockBots.php:41', 'public/index.php:15'])));
    }

    public function test_a_problem_raised_in_code_the_builder_loads_is_placed_at_the_apps_own_code()
    {
        $this->driver->files["{$this->workspace->driver_id}:storage/logs/laravel.log"] = implode("\n", [
            '[2026-10-02 21:26:00] local.ERROR: Connection could not be established with the mail server. {"exception":"[object] (Symfony\\Component\\Mailer\\Exception\\TransportException(code: 0): Connection could not be established with the mail server. at /opt/trace-recorder/src/Recorder.php:1112)',
            '[stacktrace]',
            '#0 /workspaces/w1/vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php(488): TraceRecorder\\Recorder->sending()',
            '#1 /workspaces/w1/app/Actions/Users/CreateUser.php(28): Illuminate\\Notifications\\Notifiable->notify()',
            '#2 /opt/trace-recorder/src/Middleware.php(25): App\\Actions\\Users\\CreateUser->handle()',
            '#3 /workspaces/w1/public/index.php(15): Illuminate\\Foundation\\Http\\Kernel->handle()',
            '#4 {main}',
            '"} ',
            '',
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('problems', fn (Assert $page) => $page
                ->count('problems', 1)
                ->where('problems.0.words', 'An email could not be sent.')
                ->where('problems.0.place', 'app/Actions/Users/CreateUser.php:28')
                ->where('problems.0.trace', ['public/index.php:15'])));
    }

    public function test_a_problem_met_while_the_owner_made_something_fail_on_purpose_says_so_to_the_owner_and_the_coder()
    {
        class_exists(Recorder::class) || require_once resource_path('trace-recorder/src/Recorder.php');
        $fail = fn () => report(new RuntimeException('Connection refused: the cache server did not answer.'));

        $this->writeLog(function () use ($fail) {
            $fail();
            // The recorder in the app on show marks each request while the cache is down.
            Context::add(Recorder::LIVE_CONTEXT, Recorder::LIVE_WORDS['cache']);
            $fail();
            Log::error('Could not keep the cart');
            Context::forget(Recorder::LIVE_CONTEXT);
        });
        $problems = $this->problems();

        // The same error met with the cache down on purpose is its own problem.
        $this->assertSame([
            ['Could not keep the cart', 'cache'],
            ['Connection refused: the cache server did not answer.', 'cache'],
            ['Connection refused: the cache server did not answer.', null],
        ], array_map(fn (array $problem) => [$problem['message'], $problem['during']], $problems));
        $this->assertSame('Your app stopped with an error while the cache was down.', $problems[1]['words']);
        $this->assertSame('Something went wrong in your app.', $problems[2]['words']);

        $this->actingAs($this->owner)->post(route('preview-problem-fixes.store', $this->project), ['problem' => $problems[1]['id']]);

        $instructions = FeatureRequest::sole()->instructions();
        $this->assertStringContainsString('This happened while the owner had made the cache fail on purpose, to see how the app copes without it.', $instructions);
        $this->assertStringContainsString('falling back', $instructions);
        $this->assertStringContainsString('Say in your summary which you chose and why', $instructions);
    }

    public function test_one_click_asks_for_a_fix_with_the_details_and_a_second_click_opens_it()
    {
        $this->writeLog(fn () => report(new ErrorException('Undefined array key "plan" for jane@example.com')));
        $problem = $this->problems()[0];

        $response = $this->actingAs($this->owner)->post(route('preview-problem-fixes.store', $this->project), ['problem' => $problem['id']]);

        $fix = FeatureRequest::sole();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->uuid]));
        $this->assertSame('Fix this problem I ran into while trying my app: The app used something that was not there.', $fix->prompt);
        $this->assertSame(FeatureRequestStatus::Generating, $fix->status);

        $instructions = $fix->instructions();
        $this->assertStringContainsString('The owner ran into this error while trying the app.', $instructions);
        $this->assertStringContainsString('- ErrorException: Undefined array key "plan" for [email] (at tests/Feature/Previews/PreviewProblemsTest.php:', $instructions);
        $this->assertStringContainsString("Through the app's code: tests/Feature/Previews/PreviewProblemsTest.php:", $instructions);
        $this->assertStringNotContainsString('jane@example.com', $instructions);

        $this->post(route('preview-problem-fixes.store', $this->project), ['problem' => $problem['id']])
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->uuid]));
        $this->assertSame(1, FeatureRequest::count());
    }

    public function test_a_problem_in_a_change_the_owner_tries_is_fixed_in_that_change()
    {
        $change = FeatureRequest::factory()->generated()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        Preview::factory()->ready()->create(['project_id' => $this->project->id, 'feature_request_id' => $change->id, 'workspace_id' => $this->workspace->id]);
        $this->writeLog(fn () => report(new ErrorException('Undefined array key "plan"')));
        // The tools read the copy the page names.
        $this->app->instance('request', Request::create('/?copy='.$change->uuid));
        $problem = $this->problems();
        $this->assertCount(1, $problem);

        $this->actingAs($this->owner)->post(route('preview-problem-fixes.store', ['project' => $this->project, 'copy' => $change->uuid]), ['problem' => $problem[0]['id']])
            ->assertSessionHasNoErrors();

        $fix = FeatureRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($change->id, $fix->parent_id);
        $this->assertSame('Fix this problem I ran into while trying this change: The app used something that was not there.', $fix->prompt);
        $this->assertSame($problem[0]['id'], $fix->live_errors['problem']);

        // A second click opens the same fix.
        $this->post(route('preview-problem-fixes.store', ['project' => $this->project, 'copy' => $change->uuid]), ['problem' => $problem[0]['id']])
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->uuid]));
        $this->assertSame(2, FeatureRequest::count());
    }

    public function test_a_problem_not_in_the_app_or_from_someone_else_is_not_fixed()
    {
        $this->writeLog(fn () => Log::error('Payment gateway timed out'));

        $this->actingAs($this->owner)
            ->post(route('preview-problem-fixes.store', $this->project), ['problem' => str_repeat('a', 40)])
            ->assertSessionHasErrors(['fix' => 'This problem is no longer in your app. Try again if it comes back.']);
        $this->post(route('preview-problem-fixes.store', $this->project), ['problem' => 'not-a-problem'])
            ->assertSessionHasErrors('problem');

        $this->actingAs(User::factory()->create())
            ->post(route('preview-problem-fixes.store', $this->project), ['problem' => $this->problems()[0]['id']])
            ->assertForbidden();

        $this->assertSame(0, FeatureRequest::count());
    }

    public function test_a_fix_is_not_asked_while_ai_use_is_paused_so_clicks_leave_no_stopped_changes()
    {
        $this->writeLog(fn () => Log::error('Could not send the welcome email [] '.json_encode([Recorder::LIVE_CONTEXT => Recorder::LIVE_WORDS['mail']])));
        $id = $this->problems()[0]['id'];
        config(['builder.construction.budgets.daily_usd' => 10]);
        Run::factory()->create()->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 10.5]);

        $this->actingAs($this->owner)
            ->post(route('preview-problem-fixes.store', $this->project), ['problem' => $id])
            ->assertSessionHasErrors(['fix' => SpendPause::message()]);

        // The owner's plan for this month counts too.
        config(['builder.construction.budgets.daily_usd' => 100, 'billing.plans.free.monthly_usd' => 5]);
        Run::factory()->for(FeatureRequest::factory()->for($this->project))->create()
            ->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 5.5]);

        $this->post(route('preview-problem-fixes.store', $this->project), ['problem' => $id])
            ->assertSessionHas('errors', fn ($errors) => str_starts_with((string) $errors->first('fix'), 'You have used all the AI use your plan includes this month.'));

        // Nothing was asked, so the question is still the owner's to answer.
        $this->assertFalse(FeatureRequest::query()->where('live_errors->problem', $id)->exists());
        $this->assertSame([], $this->decisions());
    }

    public function test_the_owner_can_say_failing_is_fine_while_something_is_down_and_it_stays_put_away()
    {
        class_exists(Recorder::class) || require_once resource_path('trace-recorder/src/Recorder.php');
        $this->writeLog(function () {
            Context::add(Recorder::LIVE_CONTEXT, Recorder::LIVE_WORDS['mail']);
            Log::error('Could not send the welcome email');
            Context::forget(Recorder::LIVE_CONTEXT);
        });
        $id = $this->problems()[0]['id'];

        // Deciding costs no AI: nothing is asked of the coder.
        $this->actingAs($this->owner)->post(route('cleared-problems.store', $this->project), ['problem' => $id, 'fine' => true])->assertSessionHasNoErrors();
        $this->assertSame(['fine', null], $this->stand());
        $this->assertSame(0, FeatureRequest::count());

        // It is a product decision: shown once with the others, and followed by later changes.
        $this->post(route('cleared-problems.store', $this->project), ['problem' => $id, 'fine' => true])->assertSessionHasNoErrors();
        $this->assertSame([['Should your app keep working when email is down?', 'No, failing is fine here.']], $this->decisions());

        // It will happen whenever email is down, so it does not come back.
        $this->travel(1)->minute();
        $this->happensAgain('Could not send the welcome email [] '.json_encode([Recorder::LIVE_CONTEXT => Recorder::LIVE_WORDS['mail']]));
        $this->assertSame(['fine', null], $this->stand());
        $this->assertSame(2, $this->problems()[0]['count']);

        // The owner can change their mind, and the decision goes with it.
        $this->delete(route('cleared-problems.destroy', [$this->project, $id]))->assertSessionHasNoErrors();
        $this->assertSame(['new', null], $this->stand());
        $this->assertSame([], $this->decisions());

        // Asking the app to cope is the other answer, and replaces an earlier one.
        $this->post(route('cleared-problems.store', $this->project), ['problem' => $id, 'fine' => true]);
        $this->post(route('preview-problem-fixes.store', $this->project), ['problem' => $id])->assertSessionHasNoErrors();
        $this->assertSame([['Should your app keep working when email is down?', 'Yes, it should cope.']], $this->decisions());
        $this->assertSame('Make my app keep working when email is down.', FeatureRequest::sole()->prompt);
    }

    /**
     * The owner's decisions as the Understanding page lists them.
     *
     * @return list<array{string|null, string}>
     */
    protected function decisions(): array
    {
        $notes = NotesDocument::parse(app(ProjectNotes::class)->files($this->project)[ProjectContext::PROJECT_FILE] ?? '');

        return array_map(
            fn (array $decision) => [$decision['question'], $decision['decision']],
            app(ListDecisions::class)->handle($this->project, $notes->section(RecordDecision::SECTION)),
        );
    }

    public function test_a_problem_leaves_the_list_once_fixed_or_cleared_and_comes_back_if_it_happens_again()
    {
        $this->writeLog(fn () => Log::error('Payment gateway timed out'));
        $id = $this->problems()[0]['id'];
        $this->assertSame(['new', null], $this->stand());

        // Asking for a fix: it is being fixed.
        $this->actingAs($this->owner)->post(route('preview-problem-fixes.store', $this->project), ['problem' => $id]);
        $fix = FeatureRequest::sole();
        $this->assertSame(['fixing', $fix->uuid], $this->stand());

        // A fix that stopped, here because no AI could take it, is not
        // being fixed: the problem waits again, and a click asks anew.
        $stopped = FeatureRequest::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'live_errors' => $fix->live_errors,
        ]);
        Run::factory()->create(['feature_request_id' => $stopped->id, 'status' => RunStatus::NeedsUserDecision]);
        $this->assertSame(['fixing', $fix->uuid], $this->stand());
        $fix->update(['dismissed_at' => now()]);
        $this->assertSame(['new', null], $this->stand());
        // The owner can open the try that stopped to see why.
        $this->assertSame($stopped->uuid, $this->problems()[0]['stopped']);
        $this->post(route('preview-problem-fixes.store', $this->project), ['problem' => $id]);
        $fix = FeatureRequest::latest('id')->firstOrFail();
        $this->assertNotSame($stopped->id, $fix->id);
        $this->assertSame(['fixing', $fix->uuid], $this->stand());
        $this->assertNull($this->problems()[0]['stopped']);

        // Keeping the fix: it is fixed, until the app runs into it again.
        $this->travel(1)->minute();
        $fix->update(['status' => FeatureRequestStatus::Generated, 'accepted_at' => now()]);
        $this->assertSame(['fixed', $fix->uuid], $this->stand());

        $this->travel(1)->minute();
        $this->happensAgain('Payment gateway timed out');
        $this->assertSame(['back', $fix->uuid], $this->stand());

        // A fix that did not hold is asked for again, not reopened.
        $this->post(route('preview-problem-fixes.store', $this->project), ['problem' => $id]);
        $again = FeatureRequest::latest('id')->first();
        $this->assertNotSame($fix->id, $again->id);
        $this->assertSame(['fixing', $again->uuid], $this->stand());
        $again->update(['dismissed_at' => now()]);

        // Cleared by the owner: gone until it happens again.
        $this->travel(1)->minute();
        $fix->update(['accepted_at' => null, 'dismissed_at' => now()]);
        $this->post(route('cleared-problems.store', $this->project), ['problem' => $id])->assertSessionHasNoErrors();
        $this->assertSame(['cleared', null], $this->stand());

        $this->travel(1)->minute();
        $this->happensAgain('Payment gateway timed out');
        $this->assertSame(['back', null], $this->stand());

        // Put back by the owner.
        $this->delete(route('cleared-problems.destroy', [$this->project, $id]))->assertSessionHasNoErrors();
        $this->assertSame(['new', null], $this->stand());

        $this->actingAs(User::factory()->create())->post(route('cleared-problems.store', $this->project), ['problem' => $id])->assertForbidden();
    }

    /**
     * Where the first problem stands, and the change fixing it.
     *
     * @return array{string, int|null}
     */
    protected function stand(): array
    {
        // The log is read at most every few seconds.
        $this->travel(3)->seconds();
        $problem = $this->problems()[0];

        return [$problem['state'], $problem['change']];
    }

    /**
     * @return list<array{id: string}>
     */
    protected function problems(): array
    {
        return app(ReadPreviewProblems::class)->handle($this->project);
    }

    /**
     * Log the problem again at the test's time, which the logger itself
     * does not follow.
     */
    protected function happensAgain(string $message): void
    {
        $this->driver->files["{$this->workspace->driver_id}:storage/logs/laravel.log"] .= '['.now()->format('Y-m-d H:i:s')."] testing.ERROR: {$message}\n";
    }

    /**
     * Log what the callback reports and writes, as the app on show does,
     * into the preview's log file.
     */
    protected function writeLog(callable $callback): void
    {
        $path = storage_path('framework/testing/problems-'.getmypid().'.log');
        File::delete($path);
        config(['logging.channels.preview' => ['driver' => 'single', 'path' => $path], 'logging.default' => 'preview']);

        try {
            $callback();
            $this->driver->files["{$this->workspace->driver_id}:storage/logs/laravel.log"] = File::get($path);
        } finally {
            File::delete($path);
        }
    }
}
