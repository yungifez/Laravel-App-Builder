<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetailLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_starts_at_the_plain_answer_and_the_depth_they_open_is_remembered()
    {
        $user = User::factory()->create();
        $this->assertSame(1, $user->refresh()->detail_level);

        $this->actingAs($user)
            ->from('/dashboard')
            ->patch(route('detail-level.update'), ['detail_level' => 3])
            ->assertRedirect('/dashboard');

        $this->assertSame(3, $user->refresh()->detail_level);
    }

    public function test_only_the_four_depths_are_accepted()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('detail-level.update'), ['detail_level' => 5])
            ->assertSessionHasErrors('detail_level');

        $this->assertSame(1, $user->refresh()->detail_level);
    }
}
