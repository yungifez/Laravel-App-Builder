<?php

namespace Tests\Unit;

use App\Features\CodeShortcuts;
use Tests\TestCase;

class CodeShortcutsTest extends TestCase
{
    /**
     * Make a patch that adds the given lines to a file after two lines it
     * already had, so the first added line is line 3.
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

    /**
     * The analyser's report with findings at the given places.
     *
     * @param  list<array{string, string, int}>  $findings
     */
    protected function report(array $findings): string
    {
        return json_encode(['schema' => 1, 'tool' => 'sloppy', 'findings' => array_map(fn (array $finding) => [
            'rule' => $finding[0], 'name' => 'A shortcut', 'severity' => 'high', 'confidence' => 90, 'file' => $finding[1], 'line' => $finding[2], 'message' => 'A shortcut.',
        ], $findings)]);
    }

    public function test_it_reads_the_php_code_a_change_touched_but_not_its_tests_or_removed_files()
    {
        $patch = implode("\n", [
            $this->adding('app/Http/Controllers/TeamController.php', ['$teams = Team::all();']),
            $this->adding('tests/Feature/TeamTest.php', ['Team::all();']),
            $this->adding('resources/js/pages/Team.vue', ['<p />']),
            implode("\n", ['diff --git a/app/Old.php b/app/Old.php', 'deleted file mode 100644', '--- a/app/Old.php', '+++ /dev/null']),
        ]);

        $this->assertSame(['app/Http/Controllers/TeamController.php'], CodeShortcuts::files($patch));
        $this->assertTrue(CodeShortcuts::scans($patch));
        $this->assertFalse(CodeShortcuts::scans($this->adding('resources/js/pages/Team.vue', ['<p />'])));
        $this->assertSame(['SL107', 'SL203', 'SL204', 'SL210'], CodeShortcuts::rules());
    }

    public function test_only_shortcuts_on_the_lines_a_change_adds_count_and_a_reason_lets_one_through()
    {
        $patch = $this->adding('app/Http/Controllers/TeamController.php', [
            'foreach (Team::all() as $team) {',
            '    echo $team->owner->name;',
            '}',
            'try {',
            '    $this->send();',
            '} catch (Exception $e) {',
            '}',
            'try {',
            '    $this->ping();',
            '} catch (ConnectionException) {',
            '    // The ping is optional, so a failed one changes nothing.',
            '}',
        ]);
        $shortcuts = CodeShortcuts::parse($this->report([
            ['SL210', 'app/Http/Controllers/TeamController.php', 3],
            ['SL203', 'app/Http/Controllers/TeamController.php', 4],
            ['SL107', 'app/Http/Controllers/TeamController.php', 8],
            // Explained by the comment below it.
            ['SL107', 'app/Http/Controllers/TeamController.php', 12],
            // On a line the app already had.
            ['SL204', 'app/Http/Controllers/TeamController.php', 1],
            // A rule not held against a change.
            ['SL101', 'app/Http/Controllers/TeamController.php', 3],
            ['SL107', 'app/Models/Team.php', 3],
        ]));

        $this->assertSame([
            ['rule' => 'SL210', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 3],
            ['rule' => 'SL203', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 4],
            ['rule' => 'SL107', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 8],
        ], CodeShortcuts::found($shortcuts, $patch));
        $this->assertSame('} catch (Exception $e) {', CodeShortcuts::code(['rule' => 'SL107', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 8], $patch));
        $this->assertNull(CodeShortcuts::code(['rule' => 'SL204', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 1], $patch));
        $this->assertSame(
            'Line 8 of app/Http/Controllers/TeamController.php catches an error and carries on without a trace, so when it goes wrong nobody can tell. Let it fail, or record it with report() before going on. If it is right as it is, say why in a comment on the line below.',
            CodeShortcuts::finding(['rule' => 'SL107', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 8]),
        );
    }

    public function test_anything_but_the_analysers_report_is_not_read()
    {
        $this->assertNull(CodeShortcuts::parse(''));
        $this->assertNull(CodeShortcuts::parse('PHP Fatal error: not a report'));
        $this->assertNull(CodeShortcuts::parse('{"tool":"other","findings":[]}'));
        $this->assertSame([], CodeShortcuts::parse($this->report([])));
        $this->assertSame([], CodeShortcuts::found(null, $this->adding('app/Team.php', ['Team::all();'])));
    }
}
