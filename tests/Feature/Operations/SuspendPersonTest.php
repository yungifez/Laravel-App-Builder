<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuspendPersonTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => ['ops@example.com', 'ops2@example.com']]);
        $this->operator = User::factory()->create(['email' => 'ops@example.com']);
    }

    public function test_an_operator_stops_a_person_who_is_signed_out_at_once()
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->put(route('operations.people.suspension.update', $owner), ['suspended' => true])->assertForbidden();

        $this->actingAs($this->operator)
            ->put(route('operations.people.suspension.update', $owner), ['suspended' => true])
            ->assertRedirect(route('operations.people.show', $owner));

        $this->assertTrue($owner->refresh()->suspended());

        $this->get(route('operations.people.show', $owner))
            ->assertInertia(fn (Assert $page) => $page->where('person.suspended_at', $owner->suspended_at?->toIso8601String()));
        $this->get(route('operations.people.index'))
            ->assertInertia(fn (Assert $page) => $page->where('people.data.0.suspended', true));

        // Their open session ends on the next request.
        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();
    }

    public function test_a_stopped_person_cannot_log_in_until_let_back()
    {
        $owner = User::factory()->create(['suspended_at' => now()]);

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password']);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->operator)
            ->put(route('operations.people.suspension.update', $owner), ['suspended' => false])
            ->assertRedirect();
        $this->assertFalse($owner->refresh()->suspended());

        $this->actingAs($owner)->get(route('projects.index'))->assertOk();
    }

    public function test_operators_cannot_stop_each_other_or_themselves()
    {
        $other = User::factory()->create(['email' => 'ops2@example.com']);

        $this->actingAs($this->operator)
            ->put(route('operations.people.suspension.update', $other), ['suspended' => true])
            ->assertForbidden();
        $this->put(route('operations.people.suspension.update', $this->operator), ['suspended' => true])
            ->assertForbidden();

        $this->assertFalse($other->refresh()->suspended());
    }

    public function test_an_operator_signed_in_as_a_stopped_person_may_still_look()
    {
        $owner = User::factory()->create(['suspended_at' => now()]);

        $this->actingAs($this->operator)->post(route('operations.people.sign-in', $owner))->assertRedirect();

        $this->get(route('projects.index'))->assertOk();
        $this->assertAuthenticatedAs($owner);
    }
}
