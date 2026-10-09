<?php

namespace Tests\Feature\Developers;

use App\Actions\Developers\AskDeveloper;
use App\Models\DeveloperApplication;
use App\Models\DeveloperReview;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DeveloperApplicationDecided;
use App\Notifications\DeveloperApplied;
use App\Notifications\DeveloperAsked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeveloperSignUpTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => ['ops@example.com']]);
        $this->operator = User::factory()->create(['email' => 'ops@example.com']);
    }

    public function test_anyone_reads_what_the_work_is_and_a_guest_is_sent_to_log_in()
    {
        $this->get(route('developers'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/Developers')
                ->where('signedIn', false)
                ->where('application', null));

        $this->get(route('developers.apply'))->assertRedirect(route('login'));

        // Back from logging in, the person lands on the form.
        $this->actingAs(User::factory()->create())
            ->get(route('developers.apply'))
            ->assertRedirect(route('developers').'#apply');
    }

    public function test_a_developer_asks_to_join_and_the_operators_hear()
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('developers.store'), [
                'about' => 'Eight years of Laravel: two booking platforms, a payroll app and a lot of queues.',
                'link' => 'https://github.com/example',
            ])
            ->assertRedirect(route('developers'));

        $application = $user->developerApplication;
        $this->assertTrue($application->waiting());
        $this->assertSame('https://github.com/example', $application->link);

        Notification::assertSentTo(new AnonymousNotifiable, DeveloperApplied::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === ['ops@example.com']);

        $this->get(route('developers'))
            ->assertInertia(fn (Assert $page) => $page->where('application.status', 'waiting'));

        // A waiting person cannot reach the questions yet.
        $this->get(route('operations.developer-reviews.index'))->assertForbidden();
    }

    public function test_a_request_says_enough_and_gives_a_real_address()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('developers.store'), ['about' => 'I code.', 'link' => 'javascript:alert(1)'])
            ->assertSessionHasErrors(['about', 'link']);

        $this->assertSame(0, DeveloperApplication::query()->count());
    }

    public function test_an_operator_approves_and_the_developer_can_answer_questions_only()
    {
        Notification::fake();
        $application = DeveloperApplication::factory()->create();

        $this->actingAs($this->operator)
            ->get(route('operations.developers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/Developers')
                ->where('applications.data.0.status', 'waiting'));

        $this->put(route('operations.developers.update', $application), ['approved' => true])->assertRedirect();

        $this->assertTrue($application->refresh()->approved());
        $this->assertSame($this->operator->id, $application->decided_by);
        Notification::assertSentTo($application->user, DeveloperApplicationDecided::class, fn ($notification) => $notification->approved);

        $developer = $application->user;
        $this->actingAs($developer)
            ->get(route('operations.developer-reviews.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('operations/DeveloperReviews')->where('auth.operator', false));

        // The rest of operations stays closed.
        $this->get(route('operations.attention'))->assertForbidden();
        $this->get(route('operations.people.index'))->assertForbidden();
        $this->get(route('operations.developers.index'))->assertForbidden();
    }

    public function test_approved_developers_hear_of_new_questions_but_not_about_their_own_app()
    {
        Notification::fake();
        $developer = DeveloperApplication::factory()->approved()->create()->user;
        $waiting = DeveloperApplication::factory()->create()->user;
        $project = Project::factory()->create();

        app(AskDeveloper::class)->handle($project, $project->owner, 'Is the way payments are kept sound?');
        Notification::assertSentTo([$developer, $this->operator], DeveloperAsked::class);
        Notification::assertNotSentTo($waiting, DeveloperAsked::class);

        $own = Project::factory()->for($developer, 'owner')->create();
        Notification::fake();
        app(AskDeveloper::class)->handle($own, $developer, 'Is my own app sound?');
        Notification::assertNotSentTo($developer, DeveloperAsked::class);
    }

    public function test_a_developer_takes_a_question_answers_it_and_nobody_else_can()
    {
        Notification::fake();
        $ada = DeveloperApplication::factory()->approved()->create()->user;
        $grace = DeveloperApplication::factory()->approved()->create()->user;
        $review = DeveloperReview::factory()->create();

        // Open to both until one takes it.
        $this->actingAs($grace)->get(route('operations.developer-reviews.show', $review))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.claim', true)->where('can.answer', false)->where('review.change', null));
        $this->put(route('operations.developer-reviews.update', $review), ['summary' => 'Looks fine.'])->assertForbidden();

        $this->actingAs($ada)->post(route('operations.developer-reviews.claim.store', $review))
            ->assertRedirect(route('operations.developer-reviews.show', $review));
        $this->assertSame($ada->id, $review->refresh()->claimed_by);

        // Taken: Grace no longer sees it, its code or its list row.
        $this->actingAs($grace)->get(route('operations.developer-reviews.show', $review))->assertForbidden();
        $this->get(route('operations.developer-reviews.code', $review))->assertForbidden();
        $this->post(route('operations.developer-reviews.claim.store', $review))->assertForbidden();
        $this->get(route('operations.developer-reviews.index'))
            ->assertInertia(fn (Assert $page) => $page->has('reviews.data', 0));

        $this->actingAs($ada)
            ->put(route('operations.developer-reviews.update', $review), ['summary' => 'Keep payments in one class.', 'guidance' => 'Keep every payment behind one class.'])
            ->assertRedirect(route('operations.developer-reviews.show', $review));

        $this->assertSame($ada->id, $review->refresh()->answered_by);

        $this->get(route('operations.developer-reviews.index'))
            ->assertInertia(fn (Assert $page) => $page->where('reviews.data.0.id', $review->uuid));

        // The operators see who is on it.
        $this->actingAs($this->operator)->get(route('operations.developer-reviews.index'))
            ->assertInertia(fn (Assert $page) => $page->where('reviews.data.0.taken_by', $ada->name));
    }

    public function test_a_developer_gives_a_question_back_and_another_takes_it()
    {
        $ada = DeveloperApplication::factory()->approved()->create()->user;
        $grace = DeveloperApplication::factory()->approved()->create()->user;
        $review = DeveloperReview::factory()->create(['claimed_by' => $ada->id, 'claimed_at' => now()]);

        $this->actingAs($grace)->delete(route('operations.developer-reviews.claim.destroy', $review))->assertForbidden();

        $this->actingAs($ada)->delete(route('operations.developer-reviews.claim.destroy', $review))
            ->assertRedirect(route('operations.developer-reviews.index'));
        $this->assertNull($review->refresh()->claimed_by);

        $this->actingAs($grace)->post(route('operations.developer-reviews.claim.store', $review))->assertRedirect();
        $this->assertSame($grace->id, $review->refresh()->claimed_by);
    }

    public function test_nobody_answers_about_their_own_app()
    {
        $developer = DeveloperApplication::factory()->approved()->create()->user;
        $review = DeveloperReview::factory()->for(Project::factory()->for($developer, 'owner'))->create();

        $this->actingAs($developer)->get(route('operations.developer-reviews.show', $review))->assertForbidden();
        $this->post(route('operations.developer-reviews.claim.store', $review))->assertForbidden();
        $this->get(route('operations.developer-reviews.index'))
            ->assertInertia(fn (Assert $page) => $page->has('reviews.data', 0));
    }

    public function test_taking_access_away_gives_back_the_questions_they_had_not_answered()
    {
        Notification::fake();
        $application = DeveloperApplication::factory()->approved()->create();
        $taken = DeveloperReview::factory()->create(['claimed_by' => $application->user_id, 'claimed_at' => now()]);

        $this->actingAs($this->operator)
            ->put(route('operations.developers.update', $application), ['approved' => false])
            ->assertRedirect();

        $this->assertFalse($application->refresh()->approved());
        $this->assertNull($taken->refresh()->claimed_by);
        Notification::assertSentTo($application->user, DeveloperApplicationDecided::class, fn ($notification) => ! $notification->approved);

        $this->actingAs($application->user)->get(route('operations.developer-reviews.index'))->assertForbidden();

        // They may ask again later, and wait again.
        $this->post(route('developers.store'), ['about' => 'Two more years of Laravel since then, mostly billing and queues.'])
            ->assertRedirect(route('developers'));
        $this->assertTrue($application->refresh()->waiting());
    }

    public function test_an_approved_developer_cannot_ask_again()
    {
        Notification::fake();
        $developer = DeveloperApplication::factory()->approved()->create()->user;

        $this->actingAs($developer)
            ->post(route('developers.store'), ['about' => 'Asking again although I am approved already, which changes nothing.'])
            ->assertSessionHasErrors('about');

        $this->assertTrue($developer->developerApplication->approved());
        Notification::assertNothingSent();
    }

    public function test_the_bell_opens_the_questions_for_an_approved_developer()
    {
        $application = DeveloperApplication::factory()->approved()->create();
        $application->user->notify(new DeveloperApplicationDecided(true));

        $this->actingAs($application->user)
            ->get(route('notifications.show', $application->user->notifications()->sole()->id))
            ->assertRedirect(route('operations.developer-reviews.index'));
    }
}
