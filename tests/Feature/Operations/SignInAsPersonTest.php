<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SignInAsPersonTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => ['ops@example.com', 'ops2@example.com']]);
        $this->operator = User::factory()->create(['email' => 'ops@example.com']);
    }

    public function test_an_operator_signs_in_as_a_person_and_goes_back()
    {
        $person = User::factory()->create(['name' => 'Grace']);

        $this->actingAs($this->operator)
            ->get(route('operations.people.show', $person))
            ->assertInertia(fn (Assert $page) => $page->where('person.can_sign_in_as', true));

        $this->post(route('operations.people.sign-in', $person))->assertRedirect(route('projects.index'));
        $this->assertAuthenticatedAs($person);

        // The person's pages say who is signed in, and operations is closed.
        $this->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.impersonating', true)->where('auth.operator', false));
        $this->get(route('operations.attention'))->assertForbidden();

        $this->delete(route('operations.sign-in.destroy'))->assertRedirect(route('operations.people.show', $person));
        $this->assertAuthenticatedAs($this->operator);
        $this->get(route('operations.attention'))->assertOk();
    }

    public function test_only_an_operator_signs_in_as_someone_and_never_as_another_operator()
    {
        $person = User::factory()->create();
        $other = User::factory()->create(['email' => 'ops2@example.com']);

        $this->actingAs(User::factory()->create())->post(route('operations.people.sign-in', $person))->assertForbidden();

        $this->actingAs($this->operator)->post(route('operations.people.sign-in', $other))->assertForbidden();
        $this->assertAuthenticatedAs($this->operator);

        $this->get(route('operations.people.show', $other))
            ->assertInertia(fn (Assert $page) => $page->where('person.can_sign_in_as', false));
    }

    public function test_signed_in_as_a_person_an_operator_cannot_change_how_they_sign_in_pay_or_delete_them()
    {
        $person = User::factory()->create();
        $this->actingAs($this->operator)->post(route('operations.people.sign-in', $person));

        $this->from(route('projects.index'))->put(route('user-password.update'), [
            'current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('projects.index'));
        $this->from(route('projects.index'))->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('projects.index'));
        $this->from(route('projects.index'))->post(route('billing.plan.store'), ['plan' => 'pro'])->assertRedirect(route('projects.index'));
        $this->from(route('projects.index'))->patch(route('profile.update'), ['name' => 'Changed', 'email' => 'changed@example.com'])->assertRedirect(route('projects.index'));

        $this->assertNotNull($person->fresh());
        $this->assertNotSame('Changed', $person->fresh()->name);
    }

    public function test_going_back_without_signing_in_as_someone_finds_nothing()
    {
        $this->actingAs($this->operator)->delete(route('operations.sign-in.destroy'))->assertNotFound();
    }
}
