<?php

namespace Tests\Unit;

use App\Features\ScreenCheck;
use Tests\TestCase;

class ScreenCheckTest extends TestCase
{
    /**
     * A patch that changes the given files.
     */
    protected function changing(string ...$paths): string
    {
        return implode("\n", array_map(fn (string $path) => implode("\n", [
            "diff --git a/{$path} b/{$path}",
            "--- a/{$path}",
            "+++ b/{$path}",
            '@@ -1 +1,2 @@',
            ' <template>',
            '+    <p>Team</p>',
        ]), $paths));
    }

    /**
     * One page as the measuring tool reports it.
     *
     * @param  array<int, array<string, mixed>>  $widths
     * @return array<string, mixed>
     */
    protected function page(string $path, ?string $screen, array $widths = []): array
    {
        $clean = ['overflow' => 0, 'cut_off' => 0, 'cut' => [], 'small_targets' => 0, 'small' => [], 'errors' => []];

        return ['path' => $path, 'status' => 200, 'final' => $path, 'screen' => $screen, 'widths' => [
            ['width' => 390, ...$clean, ...($widths[390] ?? [])],
            ['width' => 820, ...$clean, ...($widths[820] ?? [])],
            ['width' => 1280, ...$clean, ...($widths[1280] ?? [])],
        ]];
    }

    public function test_only_changes_to_screens_are_measured()
    {
        $this->assertTrue(ScreenCheck::scans($this->changing('resources/js/pages/Team.vue')));
        $this->assertTrue(ScreenCheck::scans($this->changing('resources/views/welcome.blade.php')));
        $this->assertTrue(ScreenCheck::scans($this->changing('resources/css/app.css')));
        $this->assertFalse(ScreenCheck::scans($this->changing('app/Models/Team.php', 'tests/Browser/TeamTest.tsx')));
    }

    public function test_it_reads_the_report_and_nothing_else()
    {
        $this->assertSame(['pages' => [['path' => '/']], 'signed_in' => true], ScreenCheck::parse("{\"pages\":[{\"path\":\"/\"}],\"signed_in\":true}\n"));
        $this->assertNull(ScreenCheck::parse(''));
        $this->assertNull(ScreenCheck::parse('Could not open a browser.'));
        $this->assertNull(ScreenCheck::parse('{"pages":{"a":1}}'));
    }

    public function test_problems_on_the_screens_the_change_touched_are_found_once_at_their_narrowest()
    {
        $screens = ['pages' => [
            $this->page('/teams', 'teams/Index', [
                390 => ['cut' => [['text' => 'Members and their roles', 'width' => 700, 'past' => 310]], 'small' => [['text' => 'Remove', 'width' => 16, 'height' => 16]]],
                820 => ['cut' => [['text' => 'Members and their roles', 'width' => 700, 'past' => 136]], 'errors' => ['team is undefined']],
            ]),
            // A second route to the same screen is the same screen.
            $this->page('/teams/all', 'teams/Index', [390 => ['overflow' => 40]]),
            // A problem the app already had on a screen the change did not touch is not the change's.
            $this->page('/billing', 'Billing', [390 => ['overflow' => 120]]),
            // A page that names no screen is never blamed.
            $this->page('/about', null, [390 => ['overflow' => 120]]),
        ]];
        $patch = $this->changing('resources/js/pages/teams/Index.vue', 'resources/js/pages/About.vue');

        $this->assertSame([
            ['kind' => 'cut', 'path' => '/teams', 'file' => 'resources/js/pages/teams/Index.vue', 'width' => 390, 'detail' => 'Members and their roles', 'size' => 310],
            ['kind' => 'small', 'path' => '/teams', 'file' => 'resources/js/pages/teams/Index.vue', 'width' => 390, 'detail' => 'Remove', 'size' => 16],
            ['kind' => 'error', 'path' => '/teams', 'file' => 'resources/js/pages/teams/Index.vue', 'width' => 820, 'detail' => 'team is undefined', 'size' => 0],
        ], ScreenCheck::found($screens, $patch));
        $this->assertSame(
            'At 390 px wide, "Members and their roles" on /teams (resources/js/pages/teams/Index.vue) runs 310 px past the edge of the screen, so part of it cannot be seen or reached. Make it fit that width: let it wrap, shrink or stack, or scroll inside its own box.',
            ScreenCheck::finding(ScreenCheck::found($screens, $patch)[0]),
        );
        $this->assertCount(1, ScreenCheck::changed($screens, $patch));
    }

    public function test_a_clean_or_missing_measurement_finds_nothing()
    {
        $patch = $this->changing('resources/js/pages/Dashboard.tsx');

        $this->assertSame([], ScreenCheck::found(['pages' => [$this->page('/dashboard', 'Dashboard')]], $patch));
        $this->assertSame([], ScreenCheck::found(null, $patch));
        $this->assertCount(1, ScreenCheck::changed(['pages' => [$this->page('/dashboard', 'Dashboard')]], $patch));
    }
}
