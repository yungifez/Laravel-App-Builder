<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PageClockTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_write_dates_in_the_owners_language_and_time_zone()
    {
        $this->actingAs(User::factory()->create())
            ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->withUnencryptedCookie('time_zone', 'America/Los_Angeles')
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clock.locale', 'en-GB')
                ->where('clock.timeZone', 'America/Los_Angeles'));
    }

    public function test_pages_write_dates_in_utc_until_the_browser_says_its_time_zone()
    {
        $this->actingAs(User::factory()->create())
            ->withUnencryptedCookie('time_zone', 'Not/AZone')
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('clock.timeZone', 'UTC'));
    }

    public function test_pages_know_a_wide_screen_from_the_browsers_cookie()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withUnencryptedCookie('screen', 'wide')
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('wideScreen', true));

        $this->withUnencryptedCookie('screen', 'narrow')
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('wideScreen', false));
    }
}
