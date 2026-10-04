<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_reads_what_changed_week_by_week_newest_first()
    {
        $this->get(route('changelog'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/Changelog')
                ->where('weeks.0.starts_on', '2026-09-28')
                ->where('weeks.0.label', 'Week of 28 September 2026')
                ->where('weeks.1.starts_on', '2026-09-21')
                ->has('weeks.0.entries.0', fn (Assert $entry) => $entry
                    ->where('title', 'Plans that include a month of AI use')
                    ->whereType('body', 'string')));
    }

    public function test_the_changelog_speaks_to_owners_without_our_internal_names()
    {
        // Owners read this page, and the home page never names the framework.
        $words = File::get(resource_path('changelog.json'));

        foreach (['Laravel', 'Livewire', 'Blade', 'Codex', 'Forge', 'Tailwind', 'Jev', 'PHPStan', 'runner', 'workspace'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $words, "The changelog says \"{$internal}\".");
        }
    }
}
