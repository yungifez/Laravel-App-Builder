<?php

namespace Tests\Unit;

use App\Features\NewCode;
use App\Features\TestMap;
use Tests\TestCase;

class NewCodeTest extends TestCase
{
    /**
     * Make a patch that adds lines 3 to 6 to a file that had two lines.
     */
    protected function adding(string $path): string
    {
        return implode("\n", [
            "diff --git a/{$path} b/{$path}",
            "--- a/{$path}",
            "+++ b/{$path}",
            '@@ -1,2 +1,6 @@',
            ' first',
            ' second',
            '+// A comment cannot run',
            '+$team->archive();',
            '+$team->notify();',
            '+return $team;',
        ]);
    }

    /**
     * The condensed line report: each file's lines that can run, with how
     * many times the suite ran each.
     *
     * @param  array<string, array<int, int>>  $files
     */
    protected function report(array $files): string
    {
        $rows = ['/workspace'];

        foreach ($files as $path => $lines) {
            $rows[] = "<file name=\"/workspace/{$path}\"";

            foreach ($lines as $number => $count) {
                $rows[] = "<line num=\"{$number}\" type=\"stmt\" count=\"{$count}\"";
            }
        }

        return implode("\n", $rows);
    }

    public function test_it_reads_each_files_lines_that_can_run_with_their_counts()
    {
        $this->assertSame(
            ['app/Models/Team.php' => [4 => 2, 5 => 0], 'app/Support/Money.php' => [9 => 1]],
            NewCode::parse($this->report(['app/Models/Team.php' => [4 => 2, 5 => 0], 'app/Support/Money.php' => [9 => 1]])),
        );
        $this->assertSame([], NewCode::parse(''));
    }

    public function test_new_lines_are_told_apart_by_which_tests_ran_them()
    {
        $map = TestMap::fromArray(
            [
                ['id' => 'Tests\Feature\TeamTest::test_owners_rename_teams', 'file' => 'tests/Feature/TeamTest.php', 'groups' => []],
                ['id' => 'Tests\Feature\ArchiveTest::test_owners_archive_teams', 'file' => 'tests/Feature/ArchiveTest.php', 'groups' => []],
            ],
            ['app/Models/Team.php' => [0, 1]],
            // A test the app had ran line 4; only the change's own test ran line 6.
            ['app/Models/Team.php' => [0 => [[4, 4]], 1 => [[4, 4], [6, 6]]]],
        );
        $patch = implode("\n", [
            $this->adding('app/Models/Team.php'),
            // Tests, screens and files the report did not measure are not counted.
            $this->adding('tests/Feature/ArchiveTest.php'),
            $this->adding('resources/js/pages/Team.vue'),
            $this->adding('config/teams.php'),
        ]);
        $lines = NewCode::parse($this->report([
            'app/Models/Team.php' => [1 => 3, 4 => 2, 5 => 0, 6 => 1],
            'tests/Feature/ArchiveTest.php' => [4 => 1],
        ]));

        $this->assertSame(
            ['lines' => 3, 'run' => 2, 'own_tests_only' => 1, 'unrun' => ['app/Models/Team.php' => [5]]],
            NewCode::measure($map, $lines, $patch, ['tests/Feature/ArchiveTest.php']),
        );
        $this->assertNull(NewCode::measure($map, $lines, $this->adding('config/teams.php'), []));
    }

    public function test_one_unrun_line_is_no_gap_but_a_large_share_is()
    {
        config(['builder.verification.change_evidence.unrun_gap_share' => 0.2]);

        $this->assertFalse(NewCode::gap(['lines' => 123, 'run' => 122]));
        $this->assertFalse(NewCode::gap(['lines' => 10, 'run' => 10]));
        $this->assertFalse(NewCode::gap(['lines' => 10, 'run' => 8]));
        $this->assertTrue(NewCode::gap(['lines' => 10, 'run' => 7]));
        $this->assertTrue(NewCode::gap(['lines' => 4, 'run' => 0]));
    }
}
