<?php

namespace Tests\Feature\Projects;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Until its first version is kept, an app started here is only the
 * template. The workspace says how the first version is going, and never
 * shows the template's welcome page as the owner's app.
 */
class FirstVersionTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['started_here' => true]);
    }

    public function test_a_first_version_that_stopped_says_why_and_offers_another_try(): void
    {
        $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        $stopped = $this->firstVersion(['status' => FeatureRequestStatus::Failed, 'error' => 'The coding service crashed.']);

        // The same words the change itself shows the owner.
        $error = $this->actingAs($this->project->owner)
            ->get(route('feature-requests.show', $stopped))
            ->viewData('page')['props']['featureRequest']['error'];

        $this->assertNotEmpty($error);
        $this->assertFirstVersion(['change' => $stopped->uuid, 'state' => 'stopped', 'error' => $error, 'can_retry' => true]);
    }

    public function test_a_first_version_being_made_and_then_ready_are_shown_until_it_is_kept(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        $this->assertFirstVersion(['change' => $change->uuid, 'state' => 'making', 'error' => null, 'can_retry' => false]);

        // Made, but the checks still run: not yet something to try.
        $change->update(['status' => FeatureRequestStatus::Generated]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Verifying]);
        $this->assertFirstVersion(['change' => $change->uuid, 'state' => 'making', 'error' => null, 'can_retry' => false]);

        $run->update(['status' => RunStatus::Completed]);
        $this->assertFirstVersion(['change' => $change->uuid, 'state' => 'ready', 'error' => null, 'can_retry' => false]);

        $change->update(['commit_sha' => 'a', 'accepted_at' => now()]);
        $this->assertFirstVersion(null);
    }

    public function test_a_first_version_made_then_stopped_in_the_review_or_asking_says_so(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generated]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => 'review_findings', 'error' => 'The review found problems this run cannot fix.']);

        $this->assertSame('stopped', $this->firstVersionShown()['state']);
        $this->assertNotNull($this->firstVersionShown()['error']);

        // A question is the owner's to answer, not a stop.
        $run->update(['question' => ['text' => 'Who books?', 'why' => '', 'options' => ['Me', 'Anyone'], 'recommended' => null]]);

        $this->assertSame('asking', $this->firstVersionShown()['state']);
    }

    public function test_an_app_brought_in_or_without_a_first_version_to_make_shows_the_app(): void
    {
        // Started here with the first version switched off: the template is
        // the app the owner chose to start from.
        $this->assertFirstVersion(null);

        $this->project->forceFill(['started_here' => false])->save();
        $this->firstVersion(['status' => FeatureRequestStatus::Failed]);

        $this->assertFirstVersion(null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function firstVersion(array $attributes): FeatureRequest
    {
        return FeatureRequest::factory()->for($this->project)->create(['prompt' => 'Make the first version: A booking page.', ...$attributes]);
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    protected function assertFirstVersion(?array $expected): void
    {
        $this->actingAs($this->project->owner)
            ->get(route('projects.show', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('first_version', $expected)->etc());
    }

    /**
     * @return array<string, mixed>
     */
    protected function firstVersionShown(): array
    {
        return $this->actingAs($this->project->owner)
            ->get(route('projects.show', $this->project))
            ->viewData('page')['props']['first_version'];
    }
}
