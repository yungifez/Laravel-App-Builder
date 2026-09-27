<?php

namespace Tests\Feature\Projects;

use App\Jobs\ExecuteRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Putting a service to use is a queued change; these tests stop there.
        Queue::fake();
    }

    public function test_an_owner_connects_payments_and_the_app_is_changed_to_take_them()
    {
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)->post(route('projects.services.store', [$project, 'payments']), [
            'keys' => ['STRIPE_KEY' => ' pk_test_abc123 ', 'STRIPE_SECRET' => 'sk_test_def456'],
        ]);

        $change = $project->featureRequests()->sole();
        $this->assertSame('Let customers pay online by card with Stripe.', $change->prompt);
        $this->assertTrue($change->user->is($project->owner));
        $response->assertRedirect(route('projects.show', ['project' => $project, 'change' => $change->id]));
        Queue::assertPushed(ExecuteRun::class);

        $project->refresh();
        $this->assertSame(['payments'], $project->connectedServices());
        $this->assertSame(['STRIPE_KEY' => 'pk_test_abc123', 'STRIPE_SECRET' => 'sk_test_def456'], $project->serviceEnvironment());
        // Kept encrypted, and never sent back to the page.
        $this->assertStringNotContainsString('sk_test_def456', (string) DB::table('projects')->where('id', $project->id)->value('service_keys'));
        $this->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('services.0.key', 'payments')
                ->where('services.0.connected', true)
                ->where('services.0.fields.1.name', 'STRIPE_SECRET')
                ->where('services.1.connected', false))
            ->assertDontSee('sk_test_def456');
    }

    public function test_new_keys_for_a_connected_service_change_only_the_keys()
    {
        $project = Project::factory()->create(['service_keys' => ['email' => ['RESEND_API_KEY' => 're_old', 'MAIL_FROM_ADDRESS' => 'hi@example.com']]]);

        $this->actingAs($project->owner)
            ->post(route('projects.services.store', [$project, 'email']), ['keys' => ['RESEND_API_KEY' => 're_new', 'MAIL_FROM_ADDRESS' => 'hello@example.com']])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $project->featureRequests()->count());
        $this->assertSame(['MAIL_MAILER' => 'resend', 'RESEND_API_KEY' => 're_new', 'MAIL_FROM_ADDRESS' => 'hello@example.com'], $project->refresh()->serviceEnvironment());
    }

    public function test_keys_that_do_not_look_right_are_refused_in_plain_words()
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.services.store', [$project, 'payments']), ['keys' => ['STRIPE_KEY' => 'sk_test_swapped', 'STRIPE_SECRET' => '']])
            ->assertSessionHasErrors([
                'keys.STRIPE_KEY' => 'That does not look like your publishable key. It starts with pk_test_.',
                'keys.STRIPE_SECRET' => 'The secret key field is required.',
            ]);

        $this->assertNull($project->refresh()->service_keys);
        $this->assertSame(0, $project->featureRequests()->count());
    }

    public function test_only_the_owner_connects_and_only_to_services_offered()
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.services.store', [$project, 'payments']), ['keys' => ['STRIPE_KEY' => 'pk_test_a', 'STRIPE_SECRET' => 'sk_test_b']])
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->post("/projects/{$project->id}/services/sms", ['keys' => []])
            ->assertNotFound();

        $this->assertNull($project->refresh()->service_keys);
    }
}
