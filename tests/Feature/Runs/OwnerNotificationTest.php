<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\TransitionRun;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
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

        app(TransitionRun::class)->handle($run, RunStatus::NeedsUserDecision);
        $notification = $owner->notifications()->sole();
        $this->assertSame('question', $notification->data['kind']);
        $this->assertSame('I have a question about your change', $notification->data['title']);
        $this->assertSame($run->feature_request_id, $notification->data['feature_request_id']);

        // A newer note about the same change replaces the unread one.
        app(TransitionRun::class)->handle($run, RunStatus::Implementing);
        app(TransitionRun::class)->handle($run, RunStatus::Failed);
        $this->assertSame('failed', $owner->notifications()->sole()->data['kind']);
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
