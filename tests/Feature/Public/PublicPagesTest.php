<?php

namespace Tests\Feature\Public;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_and_terms_pages_show_their_markdown()
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/Legal')
                ->where('title', 'Privacy')
                ->where('html', fn (string $html) => str_contains($html, '<h1>Privacy</h1>') && ! str_contains($html, 'lawyer')));

        $this->get(route('terms'))
            ->assertInertia(fn (Assert $page) => $page->where('title', 'Terms'));
    }

    public function test_a_missing_page_gets_the_builders_error_page()
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('There is nothing here');

        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', 999999))
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('public/Error')->where('status', 404));
    }

    public function test_a_failure_says_it_is_our_fault()
    {
        Route::get('test-broken', fn () => throw new \RuntimeException('Broken.'))->middleware('web');

        $this->get('test-broken')
            ->assertServerError()
            ->assertInertia(fn (Assert $page) => $page->component('public/Error')->where('status', 500));
    }

    public function test_an_expired_page_goes_back_with_a_note()
    {
        Route::post('test-expired', fn () => abort(419))->middleware('web');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post('test-expired')
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_requests_keep_their_own_errors()
    {
        $this->getJson('/no-such-page')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
