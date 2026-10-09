<?php

namespace Tests\Unit;

use App\Features\InventedColours;
use Tests\TestCase;

class InventedColoursTest extends TestCase
{
    /**
     * Make a patch that adds the given lines to a file after two lines it
     * already had.
     *
     * @param  list<string>  $lines
     */
    protected function adding(string $path, array $lines): string
    {
        return implode("\n", [
            "diff --git a/{$path} b/{$path}",
            "--- a/{$path}",
            "+++ b/{$path}",
            '@@ -1,2 +1,'.(2 + count($lines)).' @@',
            ' first',
            ' second',
            ...array_map(fn (string $line) => '+'.$line, $lines),
        ]);
    }

    public function test_it_finds_colours_a_change_makes_up_on_its_screens()
    {
        $patch = implode("\n", [
            $this->adding('resources/js/pages/Team.vue', ['<p class="bg-primary p-4">Team</p>', '<p class="text-[#1a2b3c]">Name</p>', '<p class="bg-[#fff]">Again</p>']),
            $this->adding('resources/js/components/Badge.tsx', ['<span className="hover:border-[rgb(10,20,30)]" />']),
            $this->adding('resources/views/team.blade.php', ['<div style="color: #333">Team</div>']),
            $this->adding('resources/js/pages/Plan.vue', ['<div :style="{ background: \'oklch(0.7 0.1 200)\' }" />']),
        ]);

        $this->assertSame([
            // A file with many is named once, at its first.
            ['path' => 'resources/js/pages/Team.vue', 'line' => 4],
            ['path' => 'resources/js/components/Badge.tsx', 'line' => 3],
            ['path' => 'resources/views/team.blade.php', 'line' => 3],
            ['path' => 'resources/js/pages/Plan.vue', 'line' => 3],
        ], InventedColours::found($patch));
        $this->assertStringContainsString('Line 4 of resources/js/pages/Team.vue makes up a colour', InventedColours::finding(InventedColours::found($patch)[0]));
    }

    public function test_theme_colours_old_lines_and_colours_with_a_reason_are_not_made_up()
    {
        $patch = implode("\n", [
            // The app's own line is context, not added.
            implode("\n", ['diff --git a/resources/js/Logo.vue b/resources/js/Logo.vue', '--- a/resources/js/Logo.vue', '+++ b/resources/js/Logo.vue', '@@ -1,2 +1,2 @@', ' <path fill="#ff2d20" />', '-<p>old</p>', '+<p class="text-muted-foreground">{{ label }}</p>']),
            $this->adding('resources/js/pages/Team.vue', [
                '<p class="bg-[var(--brand)] w-[73%] text-red-600">Team</p>',
                '<a href="#members">Members</a> <span>&#039;</span>',
                '<!-- The partner\'s exact brand colour, from their guidelines. -->',
                '<p class="text-[#0a66c2]">Partner</p>',
            ]),
            // The theme itself is where colours belong, and tests are not screens.
            $this->adding('resources/css/app.css', ['    --color-brand: #0a66c2;']),
            $this->adding('tests/Browser/TeamTest.php', ['$page->assertAttribute("p", "style", "color: #333");']),
        ]);

        $this->assertSame([], InventedColours::found($patch));
        $this->assertTrue(InventedColours::scans($patch));
        $this->assertFalse(InventedColours::scans($this->adding('app/Models/Team.php', ['// #fff'])));
    }
}
