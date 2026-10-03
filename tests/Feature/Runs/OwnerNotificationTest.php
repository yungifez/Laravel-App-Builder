<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\TransitionRun;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Notifications\ChangeNeedsYou;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_is_told_when_a_change_needs_them_and_only_then()
    {
        $run = Run::factory()->implementing()->create();
        $owner = $run->featureRequest->user;

        app(TransitionRun::class)->handle($run, RunStatus::Verifying);
        $this->assertSame(0, $owner->notifications()->count());

        app(TransitionRun::class)->handle($run, RunStatus::NeedsUserDecision, attributes: [
            'question' => ['text' => 'Who can invite?', 'why' => '', 'options' => ['Owners', 'Everyone'], 'recommended' => null],
        ]);
        $notification = $owner->notifications()->sole();
        $this->assertSame('question', $notification->data['kind']);
        $this->assertSame('I have a question about your change', $notification->data['title']);
        $this->assertSame($run->feature_request_id, $notification->data['feature_request_id']);

        // A newer note about the same change replaces the unread one.
        app(TransitionRun::class)->handle($run, RunStatus::Implementing);
        app(TransitionRun::class)->handle($run, RunStatus::Failed);
        $this->assertSame('failed', $owner->notifications()->sole()->data['kind']);
    }

    public function test_a_change_that_stops_without_a_question_is_told_as_not_working()
    {
        $run = Run::factory()->implementing()->create();

        app(TransitionRun::class)->handle($run, RunStatus::NeedsUserDecision, attributes: [
            'error' => 'The run finished without changing the project.',
        ]);

        $notification = $run->featureRequest->user->notifications()->sole();
        $this->assertSame('failed', $notification->data['kind']);
        $this->assertSame('Your change did not work', $notification->data['title']);
        $this->assertSame('This is our fault: I finished without changing anything in your app. Try again, or ask in other words.', $notification->data['reason']);
    }

    public function test_a_change_that_did_not_work_says_why_and_what_to_do()
    {
        $run = Run::factory()->implementing()->create();
        $owner = $run->featureRequest->user;

        app(TransitionRun::class)->handle($run, RunStatus::Failed, attributes: [
            'error' => "No AI provider could take the request.\nopenai: 429",
        ]);

        $this->assertSame('This is our fault: the AI service we use is busy right now. Try again in a few minutes.', $owner->notifications()->sole()->data['reason']);

        $this->actingAs($owner)->get(route('projects.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notifications.items.0.reason', 'This is our fault: the AI service we use is busy right now. Try again in a few minutes.'));

        // A failure with nothing kept still says whose fault it is.
        $other = Run::factory()->implementing()->create();
        app(TransitionRun::class)->handle($other, RunStatus::Failed);

        $this->assertSame('This is our fault: something went wrong on our side while I worked on this. Try again.', $other->featureRequest->user->notifications()->sole()->data['reason']);
    }

    public function test_opening_a_notification_marks_it_read_and_goes_to_the_change()
    {
        $run = Run::factory()->implementing()->create();
        $owner = $run->featureRequest->user;
        app(TransitionRun::class)->handle($run, RunStatus::Failed);
        $notification = $owner->notifications()->sole();

        $this->actingAs(User::factory()->create())
            ->get(route('notifications.show', $notification->id))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unread', 1)
                ->where('notifications.items.0.title', 'Your change did not work')
                ->where('notifications.items.0.read', false)
                // The numbers the notification keeps stay on the server.
                ->missing('notifications.items.0.feature_request_id')
                ->missing('notifications.items.0.project_id')
                ->missing('notifications.items.0.url'));

        $this->actingAs($owner)
            ->get(route('notifications.show', $notification->id))
            ->assertRedirect(route('projects.show', ['project' => $run->featureRequest->project, 'change' => $run->featureRequest->uuid]));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_trying_a_stopped_change_again_replaces_its_unread_note()
    {
        $first = Run::factory()->implementing()->create();
        $owner = $first->featureRequest->user;
        app(TransitionRun::class)->handle($first, RunStatus::Failed);

        $again = FeatureRequest::factory()->for($first->featureRequest->project)->for($owner)->create(['retry_of_id' => $first->feature_request_id]);
        app(TransitionRun::class)->handle(Run::factory()->implementing()->for($again)->create(), RunStatus::Failed);

        $this->assertSame($again->id, $owner->notifications()->sole()->data['feature_request_id']);
    }

    public function test_a_change_tried_many_times_keeps_one_note_even_once_read()
    {
        $first = Run::factory()->implementing()->create();
        $owner = $first->featureRequest->user;
        $project = $first->featureRequest->project;
        app(TransitionRun::class)->handle($first, RunStatus::Failed);
        $owner->unreadNotifications->markAsRead();

        // Two tries of the first one, then a try of the second.
        $second = FeatureRequest::factory()->for($project)->for($owner)->create(['retry_of_id' => $first->feature_request_id]);
        app(TransitionRun::class)->handle(Run::factory()->implementing()->for($second)->create(), RunStatus::Failed);
        $owner->unreadNotifications->markAsRead();
        $beside = FeatureRequest::factory()->for($project)->for($owner)->create(['retry_of_id' => $first->feature_request_id]);
        app(TransitionRun::class)->handle(Run::factory()->implementing()->for($beside)->create(), RunStatus::Failed);
        $last = FeatureRequest::factory()->for($project)->for($owner)->create(['retry_of_id' => $second->id]);
        app(TransitionRun::class)->handle(Run::factory()->implementing()->for($last)->create(), RunStatus::Failed);

        $this->assertSame($last->id, $owner->notifications()->sole()->data['feature_request_id']);

        // Another change keeps its own note.
        $other = Run::factory()->implementing()->for(FeatureRequest::factory()->for($project)->for($owner))->create();
        app(TransitionRun::class)->handle($other, RunStatus::Failed);
        $this->assertSame(2, $owner->notifications()->count());
    }

    public function test_each_notification_names_the_app_it_is_about_and_when()
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create(['name' => 'Studio Classes']);
        $run = Run::factory()->implementing()->for(FeatureRequest::factory()->for($project)->for($owner))->create();
        app(TransitionRun::class)->handle($run, RunStatus::Failed);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.items.0.app', 'Studio Classes')
                ->whereType('notifications.items.0.created_at', 'string'));
    }

    public function test_looking_at_the_change_or_marking_all_read_clears_the_notifications()
    {
        $first = Run::factory()->implementing()->create();
        $owner = $first->featureRequest->user;
        $second = Run::factory()->implementing()->for(FeatureRequest::factory()->for($first->featureRequest->project))->create();
        app(TransitionRun::class)->handle($first, RunStatus::Failed);
        app(TransitionRun::class)->handle($second, RunStatus::Failed);

        $this->actingAs($owner)->get(route('projects.show', ['project' => $first->featureRequest->project, 'change' => $first->featureRequest->uuid]));
        $this->assertSame(1, $owner->unreadNotifications()->count());

        $this->actingAs($owner)->post(route('notifications.read'))->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }

    public function test_email_is_sent_only_when_the_operator_turns_it_on()
    {
        Notification::fake();
        $run = Run::factory()->implementing()->create();
        $owner = $run->featureRequest->user;

        app(TransitionRun::class)->handle($run, RunStatus::Failed);
        Notification::assertSentTo($owner, ChangeNeedsYou::class, fn ($notification, array $channels) => $channels === ['database']);

        config(['builder.notifications.email' => true]);
        $run = Run::factory()->implementing()->for($run->featureRequest)->create();
        app(TransitionRun::class)->handle($run, RunStatus::Failed);
        Notification::assertSentTo($owner, ChangeNeedsYou::class, fn ($notification, array $channels) => $channels === ['database', 'mail']);
    }
}
