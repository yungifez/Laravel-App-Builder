<?php

namespace Tests\Feature\Previews;

use App\Actions\Previews\ReadPreviewProblems;
use App\Actions\Projects\CreateProject;
use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

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
