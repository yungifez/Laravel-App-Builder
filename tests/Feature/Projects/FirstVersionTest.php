<?php

namespace Tests\Feature\Projects;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Runs\Plan;
use App\VisualEditing\DesignDrafts;
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
        $this->assertFirstVersion(['change' => $stopped->uuid, 'state' => 'stopped', 'error' => $error, 'can_retry' => true, 'can_go_on' => false, 'plan_ran_out' => false, 'checking' => false, 'sketch' => null]);
    }

    public function test_a_first_version_stopped_for_credit_says_why_and_whose_fault_like_a_change(): void
    {
        // Stopped while it was still being made: the change's own status
        // never moved on, and its run says why.
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        Run::factory()->for($change)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => StopReason::OutOfCredit, 'error' => StopReason::OutOfCredit->said()]);

        $shown = $this->firstVersionShown();

        $this->assertSame('stopped', $shown['state']);
        $this->assertSame(StopReason::OutOfCredit->said(), $shown['error']);
        $this->assertStringNotContainsString('run', strtolower((string) $shown['error']));
        $this->assertTrue($shown['can_retry']);
        $this->assertFalse($shown['plan_ran_out']);
    }

    public function test_a_first_version_stopped_by_the_plan_leads_to_the_plan(): void
    {
        config(['billing.plans.free.monthly_usd' => 5]);
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        Run::factory()->for($change)->create(['status' => RunStatus::Failed, 'stop_reason' => StopReason::UsageLimit, 'error' => 'You have used all the AI use your plan includes this month.'])
            ->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 5.5]);

        $shown = $this->firstVersionShown();

        $this->assertSame('stopped', $shown['state']);
        $this->assertTrue($shown['plan_ran_out']);
    }

    public function test_a_first_version_the_owner_stopped_says_so_in_their_words(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Cancelled, 'error' => 'The run was cancelled.']);
        Run::factory()->for($change)->create(['status' => RunStatus::Cancelled, 'stop_reason' => StopReason::Cancelled, 'error' => null]);

        $shown = $this->firstVersionShown();

        $this->assertSame('stopped', $shown['state']);
        $this->assertSame(StopReason::Cancelled->said(), $shown['error']);
        $this->assertFalse($shown['plan_ran_out']);
    }

    public function test_a_first_version_being_made_and_then_ready_are_shown_until_it_is_kept(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        $this->assertFirstVersion(['change' => $change->uuid, 'state' => 'making', 'error' => null, 'can_retry' => false, 'can_go_on' => false, 'plan_ran_out' => false, 'checking' => false, 'sketch' => $this->sketch()]);

        // Made, while the checks still run: to try, as the list offers it,
        // but not yet to keep.
        $change->update(['status' => FeatureRequestStatus::Generated]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Verifying]);
        $this->assertFirstVersion(['change' => $change->uuid, 'state' => 'ready', 'error' => null, 'can_retry' => false, 'can_go_on' => false, 'plan_ran_out' => false, 'checking' => true, 'sketch' => $this->sketch()]);
        $this->actingAs($this->project->owner)
            ->get(route('projects.show', ['project' => $this->project, 'change' => $change->uuid]))
            ->assertInertia(fn (Assert $page) => $page->where('change.featureRequest.can_accept', false)->etc());

        $run->update(['status' => RunStatus::Completed]);
        $this->assertFirstVersion(['change' => $change->uuid, 'state' => 'ready', 'error' => null, 'can_retry' => false, 'can_go_on' => false, 'plan_ran_out' => false, 'checking' => false, 'sketch' => $this->sketch()]);

        $change->update(['commit_sha' => 'a', 'accepted_at' => now()]);
        $this->assertFirstVersion(null);
    }

    public function test_a_first_version_being_made_is_drawn_with_the_plans_parts_made_as_the_coder_changes_them(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating, 'prompt' => "Make the first version: A booking page.\n\nIt includes:\n- Rooms to book"]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Implementing, 'plan' => $this->planned([
            ['key' => 'rooms', 'kind' => 'data', 'label' => 'Rooms', 'file' => 'app/Models/Room.php', 'symbol' => 'Room', 'detail' => 'A room.'],
            ['key' => 'rooms-page', 'kind' => 'page', 'label' => 'Rooms', 'file' => 'resources/js/pages/Rooms.vue', 'symbol' => 'Rooms', 'detail' => 'The rooms.'],
            ['key' => 'bookings', 'kind' => 'page', 'label' => 'Bookings', 'file' => 'resources/js/pages/Bookings.vue', 'symbol' => 'Bookings', 'detail' => 'The bookings.'],
        ])]);
        $run->recordEvent('agent_story', ['story' => [['kind' => 'read', 'file' => 'resources/js/pages/Bookings.vue'], ['kind' => 'changed', 'file' => 'resources/js/pages/Rooms.vue']]]);

        // The plan's parts replace what the owner listed, once each; only a
        // part whose file was changed is made, not one only read.
        $this->assertSame($this->sketch([['name' => 'Rooms', 'made' => true], ['name' => 'Bookings', 'made' => false]]), $this->firstVersionShown()['sketch']);
    }

    public function test_a_first_version_planned_with_no_parts_keeps_what_the_owner_said_it_includes(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating, 'prompt' => "Make the first version: A booking page.\n\nIt includes:\n- Rooms to book\n- A list of bookings"]);
        Run::factory()->for($change)->create(['status' => RunStatus::Implementing, 'plan' => $this->planned([])]);

        $this->assertSame($this->sketch([['name' => 'Rooms to book', 'made' => false], ['name' => 'A list of bookings', 'made' => false]]), $this->firstVersionShown()['sketch']);
    }

    public function test_a_first_version_that_stops_before_it_is_planned_is_not_drawn_and_says_why(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        Run::factory()->for($change)->create(['status' => RunStatus::NeedsUserDecision, 'plan' => null, 'stop_reason' => StopReason::ProvidersUnavailable, 'error' => StopReason::ProvidersUnavailable->said()]);

        $shown = $this->firstVersionShown();

        $this->assertSame('stopped', $shown['state']);
        $this->assertSame(StopReason::ProvidersUnavailable->said(), $shown['error']);
        $this->assertNull($shown['sketch']);
    }

    public function test_a_first_version_handed_to_the_owners_tool_waits_until_the_tool_asks_for_it(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Implementing, 'driver' => 'worker']);
        $run->recordEvent('status', ['from' => 'planning', 'to' => 'implementing']);

        // Nothing is made until their tool asks, so no spinner says it is.
        $this->assertSame('waiting', $this->firstVersionShown()['state']);

        $run->recordEvent('worker_query', ['tool' => 'get_task']);

        $this->assertSame('making', $this->firstVersionShown()['state']);
    }

    public function test_a_first_version_we_write_is_being_made_without_waiting_for_a_tool(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Implementing, 'driver' => 'sdk']);
        $run->recordEvent('status', ['from' => 'planning', 'to' => 'implementing']);

        $this->assertSame('making', $this->firstVersionShown()['state']);
    }

    public function test_a_first_version_sent_back_to_the_owners_tool_waits_again_whatever_it_asked_before(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Implementing, 'driver' => 'worker']);
        $run->recordEvent('status', ['from' => 'planning', 'to' => 'implementing']);
        $run->recordEvent('worker_submitted', ['patch' => '', 'summary' => '']);
        $run->recordEvent('status', ['from' => 'verifying', 'to' => 'implementing']);

        $this->assertSame('waiting', $this->firstVersionShown()['state']);
    }

    public function test_a_made_first_version_sent_back_to_be_fixed_is_not_ready(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generated]);
        $run = Run::factory()->for($change)->create(['status' => RunStatus::Implementing, 'driver' => 'worker']);
        $run->recordEvent('status', ['from' => 'verifying', 'to' => 'implementing']);

        // Their tool has not asked for it again yet.
        $this->assertSame('waiting', $this->firstVersionShown()['state']);

        // Being fixed, by their tool or ours.
        $run->recordEvent('worker_query', ['tool' => 'get_task']);
        $this->assertSame('making', $this->firstVersionShown()['state']);
        $run->update(['driver' => 'sdk']);
        $this->assertSame('making', $this->firstVersionShown()['state']);

        // Checked again: ready to try once more.
        $run->update(['status' => RunStatus::Verifying]);
        $this->assertSame('ready', $this->firstVersionShown()['state']);
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

    public function test_a_first_version_waiting_on_a_finding_asks_for_an_answer_instead_of_another_try(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generated]);
        Run::factory()->for($change)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => StopReason::FindingProposed, 'error' => 'I asked you about something the checks found.']);
        $proposal = $change->findingProposals()->create(['kind' => 'owner_unchecked', 'identity' => 'owner_unchecked|App\Models\Item', 'reason' => 'Items are shared by the household.']);

        $this->assertSame('asking', $this->firstVersionShown()['state']);
        $this->assertFalse($this->firstVersionShown()['can_retry']);

        // Once answered, the run's own stop shows again.
        $proposal->update(['agreed' => true]);

        $this->assertSame('stopped', $this->firstVersionShown()['state']);
    }

    public function test_a_first_version_stopped_for_another_decision_is_not_asking(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generated]);
        Run::factory()->for($change)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => StopReason::ReviewFindings, 'error' => 'The review found problems this run cannot fix.']);
        $change->findingProposals()->create(['kind' => 'owner_unchecked', 'identity' => 'owner_unchecked|App\Models\Item', 'reason' => 'Items are shared by the household.']);

        $this->assertSame('stopped', $this->firstVersionShown()['state']);
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

    public function test_design_edits_on_the_template_wait_unoffered_until_the_first_version_is_kept(): void
    {
        $change = $this->firstVersion(['status' => FeatureRequestStatus::Generating]);
        $draft = FeatureRequest::factory()->for($this->project)->create([
            'generator' => DesignDrafts::GENERATOR,
            'status' => FeatureRequestStatus::Generated,
            'patch' => "--- a/x\n+++ b/x\n",
        ]);

        $this->assertDesignEditsShown(false);

        // Kept by a direct send too, it would land under the first version.
        $this->actingAs($this->project->owner)
            ->post(route('design-edits.store', $this->project))
            ->assertSessionHasErrors(['keep' => "Your app's first version is not kept yet. Keep your design edits once it is."]);
        $this->assertSame(0, $draft->verifications()->count());

        // Stopped is still not kept.
        $change->update(['status' => FeatureRequestStatus::Failed]);
        $this->assertDesignEditsShown(false);

        $change->update(['status' => FeatureRequestStatus::Generated, 'commit_sha' => 'a', 'accepted_at' => now()]);
        $this->assertDesignEditsShown(true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function firstVersion(array $attributes): FeatureRequest
    {
        return FeatureRequest::factory()->for($this->project)->create(['prompt' => 'Make the first version: A booking page.', ...$attributes]);
    }

    /**
     * A whole plan with the given steps.
     *
     * @param  list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>  $steps
     * @return array<string, mixed>
     */
    protected function planned(array $steps): array
    {
        return (new Plan('A booking page.', acceptanceCriteria: ['Members book rooms.'], steps: $steps))->toArray();
    }

    /**
     * The drawing of an app with no repository to read its look from.
     *
     * @param  list<array{name: string, made: bool}>  $parts
     * @return array<string, mixed>
     */
    protected function sketch(array $parts = [], ?string $now = null): array
    {
        return ['name' => $this->project->name, 'look' => null, 'parts' => $parts, 'now' => $now];
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

    protected function assertDesignEditsShown(bool $shown): void
    {
        $designEdits = $this->actingAs($this->project->owner)
            ->get(route('projects.show', $this->project))
            ->viewData('page')['props']['designEdits'];

        $this->assertSame($shown, $designEdits !== null);
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
