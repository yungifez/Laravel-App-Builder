<?php

namespace Tests\Feature\Previews;

use App\Actions\Previews\ReadPreviewProblems;
use App\Actions\Projects\CreateProject;
use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use ErrorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_one_click_asks_for_a_fix_with_the_details_and_a_second_click_opens_it()
    {
        $this->writeLog(fn () => report(new ErrorException('Undefined array key "plan" for jane@example.com')));
        $problem = $this->problems()[0];

        $response = $this->actingAs($this->owner)->post(route('preview-problem-fixes.store', $this->project), ['problem' => $problem['id']]);

        $fix = FeatureRequest::sole();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->id]));
        $this->assertSame('Fix this problem I ran into while trying my app: The app used something that was not there.', $fix->prompt);
        $this->assertSame(FeatureRequestStatus::Generating, $fix->status);

        $instructions = $fix->instructions();
        $this->assertStringContainsString('The owner ran into this error while trying the app.', $instructions);
        $this->assertStringContainsString('- ErrorException: Undefined array key "plan" for [email] (at tests/Feature/Previews/PreviewProblemsTest.php:', $instructions);
        $this->assertStringContainsString("Through the app's code: tests/Feature/Previews/PreviewProblemsTest.php:", $instructions);
        $this->assertStringNotContainsString('jane@example.com', $instructions);

        $this->post(route('preview-problem-fixes.store', $this->project), ['problem' => $problem['id']])
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->id]));
        $this->assertSame(1, FeatureRequest::count());
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

    /**
     * @return list<array{id: string}>
     */
    protected function problems(): array
    {
        return app(ReadPreviewProblems::class)->handle($this->project);
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
