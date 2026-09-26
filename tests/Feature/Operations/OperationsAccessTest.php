<?php

namespace Tests\Feature\Operations;

use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_nobody_is_an_operator_unless_listed()
    {
        config(['operations.operators' => []]);
        $user = User::factory()->create();
        $change = FeatureRequest::factory()->create();

        foreach ([route('operations.attention'), route('operations.changes.index'), route('operations.changes.show', $change)] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public function test_an_owner_cannot_see_platform_wide_records_even_their_own()
    {
        config(['operations.operators' => ['ops@example.com']]);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $change = FeatureRequest::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('operations.changes.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('operations.changes.show', $change))->assertForbidden();
        $this->actingAs($owner)->get(route('projects.index'))->assertInertia(fn (Assert $page) => $page->where('auth.operator', false));
    }

    public function test_a_listed_address_must_be_verified()
    {
        config(['operations.operators' => ['ops@example.com']]);
        $unverified = User::factory()->unverified()->create(['email' => 'ops@example.com']);

        $this->actingAs($unverified)->get(route('operations.attention'))->assertRedirect(route('verification.notice'));
        $this->assertFalse($unverified->can('viewOperations'));
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get(route('operations.attention'))->assertRedirect(route('login'));
    }

    public function test_a_listed_operator_sees_every_screen_in_any_letter_case()
    {
        config(['operations.operators' => ['ops@example.com']]);
        $operator = User::factory()->create(['email' => 'Ops@Example.com']);
        $change = FeatureRequest::factory()->create();

        $this->actingAs($operator)->get(route('operations.attention'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/Attention')
            ->where('auth.operator', true));
        $this->actingAs($operator)->get(route('operations.changes.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('operations/Changes'));
        $this->actingAs($operator)->get(route('operations.changes.show', $change))->assertOk()->assertInertia(fn (Assert $page) => $page->component('operations/Change'));
    }
}
