<?php

namespace Tests\Unit;

use App\Features\UndescribedImages;
use Tests\TestCase;

class UndescribedImagesTest extends TestCase
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

    public function test_it_finds_pictures_a_change_adds_without_a_description()
    {
        $patch = implode("\n", [
            $this->adding('resources/js/pages/Team.vue', ['<img src="/logo.svg" alt="Acme">', '<p>Team</p>', '<img src="/team.png" class="size-8" />']),
            // A tag written over several lines, with an arrow that does not close it.
            $this->adding('resources/js/components/Avatar.vue', ['<img', '    :src="user.avatar"', '    @error="() => (broken = true)"', '    class="rounded-full"', '/>']),
            $this->adding('resources/views/team.blade.php', ['<img src="{{ $team->logo_url }}">']),
        ]);

        $this->assertSame([
            ['path' => 'resources/js/pages/Team.vue', 'line' => 5],
            ['path' => 'resources/js/components/Avatar.vue', 'line' => 3],
            ['path' => 'resources/views/team.blade.php', 'line' => 3],
        ], UndescribedImages::found($patch));
        $this->assertStringContainsString('Line 5 of resources/js/pages/Team.vue adds a picture', UndescribedImages::finding(UndescribedImages::found($patch)[0]));
    }

    public function test_described_decorative_old_and_unknown_pictures_pass()
    {
        $patch = implode("\n", [
            // The app's own picture is context, not added.
            implode("\n", ['diff --git a/resources/js/Logo.vue b/resources/js/Logo.vue', '--- a/resources/js/Logo.vue', '+++ b/resources/js/Logo.vue', '@@ -1,2 +1,2 @@', ' <img src="/old.png">', '-<p>old</p>', '+<p>new</p>']),
            $this->adding('resources/js/pages/Team.vue', [
                '<img src="/divider.svg" alt="" />',
                '<img',
                '    :src="team.logo"',
                '    :alt="`${team.name} logo`"',
                '/>',
                // Attributes spread in from elsewhere may hold the description.
                '<img v-bind="logoProps" />',
            ]),
            $this->adding('resources/js/components/Logo.tsx', ['<img {...props} />']),
        ]);

        $this->assertSame([], UndescribedImages::found($patch));
        $this->assertTrue(UndescribedImages::scans($patch));
        $this->assertFalse(UndescribedImages::scans($this->adding('resources/js/pages/Team.vue', ['<p>No pictures</p>'])));
    }

    public function test_a_tag_that_runs_into_lines_the_change_did_not_add_is_unknown()
    {
        $patch = implode("\n", [
            'diff --git a/resources/js/pages/Team.vue b/resources/js/pages/Team.vue',
            '--- a/resources/js/pages/Team.vue',
            '+++ b/resources/js/pages/Team.vue',
            '@@ -1,2 +1,3 @@',
            '+<img',
            '     :src="team.logo"',
            '     alt="Team logo" />',
        ]);

        $this->assertSame([], UndescribedImages::found($patch));
    }
}
