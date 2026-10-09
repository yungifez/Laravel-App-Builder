<?php

namespace Tests\Feature\Understanding;

use App\Actions\Projects\CreateProject;
use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Enums\HealthCheckStatus;
use App\Models\FeatureRequest;
use App\Models\HealthCheck;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class HealthFixTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected HealthCheck $failed;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the fix is not what these tests are about.
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));

        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);

        $this->failed = HealthCheck::factory()->for($this->project)->create([
            'commit_sha' => app(ProjectRepository::class)->head($this->project),
            'status' => HealthCheckStatus::Failed,
            'results' => [
                ['name' => 'Install PHP dependencies', 'kind' => 'setup', 'passed' => true],
                ['name' => 'Tests', 'kind' => 'check', 'passed' => false, 'output' => "FAILED  Tests\\Feature\\CartTest > it totals the cart\nExpected 12 but got 10"],
                ['name' => 'PHP formatting', 'kind' => 'check', 'passed' => true],
                ['name' => 'JavaScript packages', 'kind' => 'packages', 'passed' => false, 'packages' => ['axios', 'vue']],
                // A lookup that could not be read has nothing to fix.
                ['name' => 'PHP packages', 'kind' => 'packages', 'passed' => false, 'packages' => null],
            ],
            'finished_at' => now(),
        ]);
    }

    public function test_one_click_asks_for_a_fix_in_plain_words_and_gives_the_builder_what_failed()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('health.fixable', true));

        $response = $this->actingAs($this->owner)->post(route('health-fixes.store', $this->project));

        $fix = FeatureRequest::sole();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->uuid]));
        $this->assertSame('Fix what the check of my app found.', $fix->prompt);
        $this->assertSame(FeatureRequestStatus::Generating, $fix->status);
        $this->assertNull($fix->experiment_id);
        $this->assertSame($this->failed->id, $fix->failed_checks['health_check_id'] ?? null);

        $instructions = $fix->instructions();
        $this->assertStringContainsString('when the owner had the app\'s current version checked', $instructions);
        $this->assertStringContainsString('CartTest > it totals the cart', $instructions);
        $this->assertStringContainsString('Known high or critical security problems in: axios, vue.', $instructions);
        $this->assertStringContainsString('Do not skip, weaken or delete a check or a test', $instructions);
        $this->assertStringNotContainsString('PHP formatting', $instructions);
        $this->assertStringNotContainsString('PHP packages', $instructions);
        $this->assertStringNotContainsString('put the app online', $instructions);
    }

    public function test_a_second_click_opens_the_fix_already_asked_for()
    {
        $this->actingAs($this->owner)->post(route('health-fixes.store', $this->project));
        $first = FeatureRequest::sole();

        $this->actingAs($this->owner)
            ->post(route('health-fixes.store', $this->project))
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $first->uuid]));

        $this->assertSame(1, FeatureRequest::count());
    }

    public function test_a_check_that_found_nothing_to_fix_offers_no_fix()
    {
        foreach ([HealthCheckStatus::Passed, HealthCheckStatus::Errored] as $status) {
            $this->failed->update(['status' => $status]);

            $this->actingAs($this->owner)
                ->get(route('projects.understanding.show', $this->project))
                ->assertInertia(fn (Assert $page) => $page->where('health.fixable', false));

            $this->actingAs($this->owner)
                ->post(route('health-fixes.store', $this->project))
                ->assertSessionHasErrors(['fix' => 'The last check found nothing to fix. Check your app again to see where it stands.']);
        }

        $this->assertSame(0, FeatureRequest::count());
    }

    public function test_a_check_of_an_earlier_version_offers_no_fix()
    {
        $this->failed->update(['commit_sha' => str_repeat('b', 40)]);

        $this->actingAs($this->owner)
            ->post(route('health-fixes.store', $this->project))
            ->assertSessionHasErrors('fix');

        $this->assertSame(0, FeatureRequest::count());
    }

    public function test_only_people_who_may_ask_for_changes_can_ask_for_the_fix()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('health-fixes.store', $this->project))
            ->assertForbidden();

        $this->assertSame(0, FeatureRequest::count());
    }
}
