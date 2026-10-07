<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_starts_without_technical_details_and_can_turn_them_on()
    {
        $user = User::factory()->create();
        $this->assertFalse($user->refresh()->technical_details);

        $this->actingAs($user)
            ->from('/projects')
            ->patch(route('technical-details.update'), ['technical_details' => true])
            ->assertRedirect('/projects');

        $this->assertTrue($user->refresh()->technical_details);
    }

    public function test_technical_details_can_be_turned_off_again()
    {
        $user = User::factory()->create(['technical_details' => true]);

        $this->actingAs($user)
            ->patch(route('technical-details.update'), ['technical_details' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->technical_details);
    }

    public function test_only_on_or_off_is_accepted()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('technical-details.update'), ['technical_details' => 'everything'])
            ->assertSessionHasErrors('technical_details');

        $this->assertFalse($user->refresh()->technical_details);
    }
}
