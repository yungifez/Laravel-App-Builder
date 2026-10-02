<?php

namespace Tests\Unit;

use App\Features\NodeInPhpTests;
use Tests\TestCase;

class NodeInPhpTestsTest extends TestCase
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

    public function test_it_finds_php_tests_that_start_node_themselves()
    {
        $patch = implode("\n", [
            // A test that renders a page with its own Node script.
            $this->adding('tests/Feature/ClassScreensTest.php', [
                '$page = $this->get(route(\'classes.index\'))->viewData(\'page\');',
                '$renderer = new Process([',
                '    \'node\',',
                '    \'tests/Support/render-class-page.mjs\',',
                '], base_path());',
            ]),
            $this->adding('tests/Feature/BuildTest.php', ['Process::run(\'npx vite build\')->throw();']),
            $this->adding('tests/Browser/ShotTest.php', ['shell_exec("bun run shot");']),
        ]);

        $this->assertSame([
            ['path' => 'tests/Feature/ClassScreensTest.php', 'line' => 4],
            ['path' => 'tests/Feature/BuildTest.php', 'line' => 3],
            ['path' => 'tests/Browser/ShotTest.php', 'line' => 3],
        ], NodeInPhpTests::found($patch));
        $this->assertStringContainsString('assertInertia', NodeInPhpTests::finding(['path' => 'tests/Feature/BuildTest.php', 'line' => 3]));
    }

    public function test_it_lets_through_tests_that_only_mention_node_or_run_other_commands()
    {
        $patch = implode("\n", [
            $this->adding('tests/Feature/ToolsTest.php', ['$this->assertSame(\'node\', $tool->name);']),
            $this->adding('tests/Feature/ArtisanTest.php', ['Process::run([\'php\', \'artisan\', \'about\']);']),
            // Not a PHP test: the app's own scripts may run Node.
            $this->adding('app/Console/Commands/BuildScreens.php', ['Process::run(\'npm run build\');', 'new Process([\'node\', \'x.mjs\']);']),
        ]);

        $this->assertSame([], NodeInPhpTests::found($patch));
        $this->assertTrue(NodeInPhpTests::scans($patch));
        $this->assertFalse(NodeInPhpTests::scans($this->adding('app/Models/Team.php', ['// Team'])));
    }
}
