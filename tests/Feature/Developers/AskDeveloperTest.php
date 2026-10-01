<?php

namespace Tests\Feature\Developers;

use App\Context\NotesDocument;
use App\Context\ProjectNotes;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\DeveloperReview;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Notifications\DeveloperAnswered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AskDeveloperTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => ['dev@example.com']]);
        $this->developer = User::factory()->create(['name' => 'Ada', 'email' => 'dev@example.com']);
        $this->project = Project::factory()->create(['name' => 'Bright Cleaning']);

        app(ProjectNotes::class)->put($this->project, 'main', [
            'project.md' => "# Bright Cleaning\n\nHouse cleaners take bookings.\n\n## Rules\n\n- Customers are never charged twice.\n\n## Decisions\n\n- Who can cancel? Only managers.\n",
            'capabilities/bookings.md' => "---\ncapability: bookings\nsummary: Customers book a cleaner.\npaths: [app/Bookings/*]\n---\n# Bookings\n\n## Rules\n\n- A booking has one cleaner.\n",
        ]);
    }

    public function test_the_owner_asks_about_the_whole_app_and_the_developer_reads_what_matters()
    {
        $this->actingAs($this->project->owner)
            ->post(route('projects.developers.store', $this->project), ['question' => 'Is the way bookings are paid still sound?'])
            ->assertRedirect(route('projects.developers.index', $this->project));

        $review = $this->project->developerReviews()->sole();

        $this->assertNull($review->feature_request_id);
        $this->assertTrue($review->waiting());
        $this->assertStringContainsString('Is the way bookings are paid still sound?', $review->bundle);
        $this->assertStringContainsString('House cleaners take bookings.', $review->bundle);
        $this->assertStringContainsString('- Customers are never charged twice.', $review->bundle);
        $this->assertStringContainsString('- Who can cancel? Only managers.', $review->bundle);
        $this->assertStringContainsString('### Bookings', $review->bundle);
        $this->assertStringContainsString('- A booking has one cleaner.', $review->bundle);

        $this->get(route('projects.developers.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Developers')
                ->where('reviews.0.question', 'Is the way bookings are paid still sound?')
                ->where('reviews.0.waiting', true));
    }

    public function test_asking_about_a_change_shows_how_it_was_understood_what_must_stay_and_its_code()
    {
        $change = FeatureRequest::factory()->for($this->project)->create([
            'prompt' => 'Let customers pay a deposit.',
            'status' => FeatureRequestStatus::Generated,
            'base_revision' => str_repeat('a', 40),
            'patch' => "diff --git a/app/Bookings/Deposit.php b/app/Bookings/Deposit.php\n+<?php\n",
        ]);
        Run::factory()->for($change)->create([
            'status' => RunStatus::Completed,
            'plan' => ['summary' => 'Take a deposit when booking.', 'acceptance_criteria' => ['A deposit is taken.'], 'assumptions' => ['Deposits are 20%.'], 'tasks' => ['Add deposits.'], 'steps' => [], 'acceptance' => [], 'solution_key' => null, 'preserve' => [['area' => 'bookings', 'statement' => 'A booking has one cleaner.']]],
            'context' => ['mode' => 'selective', 'targets' => ['bookings'], 'text' => "# Bookings\n\nCustomers book a cleaner.", 'included' => [], 'outline' => [], 'problems' => []],
            'review' => ['approved' => false, 'summary' => '', 'findings' => [['severity' => 'major', 'summary' => 'The deposit is charged twice on retry.', 'file' => 'app/Bookings/Deposit.php']], 'changes' => [], 'classification' => ['requested' => ['bookings' => []], 'may_also_affect' => [], 'unexpected' => [], 'unclaimed' => [], 'context_updates' => [], 'targets' => ['bookings']]],
        ]);

        $this->actingAs($this->project->owner)
            ->post(route('projects.developers.store', $this->project), ['question' => 'Is this safe to keep?', 'change' => $change->uuid])
            ->assertSessionHasNoErrors();

        $review = $this->project->developerReviews()->sole();

        $this->assertSame($change->id, $review->feature_request_id);
        $this->assertSame(str_repeat('a', 40), $review->revision);

        foreach (['Let customers pay a deposit.', 'Take a deposit when booking.', '- A booking has one cleaner.', '- Deposits are 20%.', '- Bookings', 'Major: The deposit is charged twice on retry.', '`app/Bookings/Deposit.php`', "```diff\ndiff --git", 'The change is not in it yet'] as $text) {
            $this->assertStringContainsString($text, $review->bundle);
        }
    }

    public function test_what_the_developer_reads_holds_nothing_of_how_we_work()
    {
        $this->actingAs($this->project->owner)->post(route('projects.developers.store', $this->project), ['question' => 'Is it sound?']);

        $bundle = $this->project->developerReviews()->sole()->bundle;

        $this->assertDoesNotMatchRegularExpression('/\b(builder|platform|capabilit(y|ies)|effects?|confidence|score|control plane|context pack)\b/i', $bundle);
    }

    public function test_a_change_of_another_app_cannot_be_asked_about()
    {
        $other = FeatureRequest::factory()->create();

        $this->actingAs($this->project->owner)
            ->get(route('projects.developers.index', ['project' => $this->project, 'change' => 'dc112e33']))
            ->assertInertia(fn (Assert $page) => $page->where('change', null));
        $this->get(route('projects.developers.index', ['project' => $this->project, 'change' => $other->uuid]))
            ->assertInertia(fn (Assert $page) => $page->where('change', null));

        $this->actingAs($this->project->owner)
            ->post(route('projects.developers.store', $this->project), ['question' => 'Is this safe?', 'change' => $other->uuid])
            ->assertSessionHasErrors('change');

        $this->assertSame(0, DeveloperReview::query()->count());
    }

    public function test_only_the_owner_asks_and_sees_the_answers()
    {
        $stranger = User::factory()->create();
        $review = DeveloperReview::factory()->for($this->project)->create(['user_id' => $this->project->user_id]);

        $this->actingAs($stranger)->post(route('projects.developers.store', $this->project), ['question' => 'Hi'])->assertForbidden();
        $this->actingAs($stranger)->get(route('projects.developers.index', $this->project))->assertForbidden();
        $this->actingAs($stranger)->delete(route('developer-reviews.destroy', $review))->assertForbidden();
        $this->actingAs($stranger)->post(route('developer-reviews.guidance.store', $review), ['points' => [0]])->assertForbidden();
    }

    public function test_our_developer_answers_from_operations_and_the_owner_is_told_once()
    {
        Notification::fake();
        $review = DeveloperReview::factory()->for($this->project)->create(['user_id' => $this->project->user_id]);

        $this->actingAs($this->developer)
            ->get(route('operations.developer-reviews.index'))
            ->assertInertia(fn (Assert $page) => $page->component('operations/DeveloperReviews')->where('reviews.data.0.waiting', true));

        $this->get(route('operations.developer-reviews.show', $review))
            ->assertInertia(fn (Assert $page) => $page->component('operations/DeveloperReview')->has('review.request'));

        $answer = ['summary' => 'Payments are fine, but retries are not safe.', 'findings' => "- Retrying a payment charges again.\n", 'guidance' => "- Make every payment safe to retry.\n- Keep payments behind one class."];

        $this->put(route('operations.developer-reviews.update', $review), $answer)->assertRedirect(route('operations.developer-reviews.show', $review));
        $this->put(route('operations.developer-reviews.update', $review), $answer);

        $review->refresh();
        $this->assertSame($this->developer->id, $review->answered_by);
        $this->assertSame(['Retrying a payment charges again.'], $review->answer['findings']);
        $this->assertSame(['Make every payment safe to retry.', 'Keep payments behind one class.'], $review->answer['guidance']);
        Notification::assertSentToTimes($this->project->owner, DeveloperAnswered::class, 1);
    }

    public function test_only_our_developers_open_the_questions()
    {
        $review = DeveloperReview::factory()->for($this->project)->create(['user_id' => $this->project->user_id]);

        $this->actingAs($this->project->owner)->get(route('operations.developer-reviews.show', $review))->assertForbidden();
        $this->actingAs($this->project->owner)->put(route('operations.developer-reviews.update', $review), ['summary' => 'Fine.'])->assertForbidden();
        $this->actingAs($this->project->owner)->get(route('operations.developer-reviews.request', $review))->assertForbidden();
    }

    public function test_the_owner_keeps_the_guidance_they_choose_and_every_later_change_reads_it()
    {
        $review = DeveloperReview::factory()->for($this->project)->create([
            'user_id' => $this->project->user_id,
            'answered_by' => $this->developer->id,
            'answered_at' => now(),
            'answer' => ['summary' => 'Mostly fine.', 'findings' => [], 'guidance' => ['Make every payment safe to retry.', 'Use a queue for email.']],
        ]);

        $this->actingAs($this->project->owner)
            ->post(route('developer-reviews.guidance.store', $review), ['points' => [0]])
            ->assertRedirect(route('projects.developers.index', $this->project));

        $notes = NotesDocument::parse(app(ProjectNotes::class)->files($this->project, 'main')['project.md']);

        $this->assertSame(['Make every payment safe to retry. (Ada, '.now()->toFormattedDayDateString().')'], $notes->items('Engineering direction'));
        $this->assertSame(['Customers are never charged twice.'], $notes->items('Rules'));
        $this->assertNotNull($review->refresh()->guidance_kept_at);

        // Kept guidance is part of the app's record now.
        $this->actingAs($this->developer)
            ->put(route('operations.developer-reviews.update', $review), ['summary' => 'Changed my mind.'])
            ->assertSessionHasErrors('summary');
        $this->actingAs($this->project->owner)
            ->post(route('developer-reviews.guidance.store', $review), ['points' => [1]])
            ->assertSessionHasErrors('points');
    }

    public function test_the_owner_takes_a_waiting_question_back()
    {
        $review = DeveloperReview::factory()->for($this->project)->create(['user_id' => $this->project->user_id]);

        $this->actingAs($this->project->owner)->delete(route('developer-reviews.destroy', $review))->assertRedirect();

        $this->assertNotNull($review->refresh()->withdrawn_at);
        $this->actingAs($this->developer)
            ->put(route('operations.developer-reviews.update', $review), ['summary' => 'Too late.'])
            ->assertSessionHasErrors('summary');
    }
}
