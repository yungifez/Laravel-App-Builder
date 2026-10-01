<?php

namespace Tests\Unit;

use App\Features\PatchSummary;
use PHPUnit\Framework\TestCase;

class PatchSummaryTest extends TestCase
{
    public function test_it_splits_a_patch_into_files_with_line_counts()
    {
        $patch = implode("\n", [
            'diff --git a/app/A.php b/app/A.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/app/A.php',
            '@@ -0,0 +1,2 @@',
            '+<?php',
            '+// a',
            'diff --git a/config/b.php b/config/b.php',
            '--- a/config/b.php',
            '+++ b/config/b.php',
            '@@ -1,3 +1,2 @@',
            ' keep',
            '-gone',
            '-also gone',
            '+added',
            '',
        ]);

        $files = PatchSummary::files($patch);

        $this->assertSame(['app/A.php', 'config/b.php'], array_column($files, 'path'));
        $this->assertSame([2, 1], array_column($files, 'additions'));
        $this->assertSame([0, 2], array_column($files, 'deletions'));
        $this->assertStringStartsWith('diff --git a/app/A.php', $files[0]['diff']);
        $this->assertStringEndsWith('+added', $files[1]['diff']);
    }

    public function test_an_empty_patch_has_no_files()
    {
        $this->assertSame([], PatchSummary::files(null));
        $this->assertSame([], PatchSummary::files(''));
    }

    public function test_a_file_is_rebuilt_as_it_was_before_its_diff()
    {
        $before = "one\ntwo\nthree\nfour\nfive\nsix\nseven\neight\nnine";
        $after = "one\nTWO\nthree\nfour\nfive\nsix\neight\nnine\nten";
        $diff = implode("\n", [
            'diff --git a/notes.php b/notes.php',
            '--- a/notes.php',
            '+++ b/notes.php',
            '@@ -1,3 +1,3 @@',
            ' one',
            '-two',
            '+TWO',
            ' three',
            '@@ -6,2 +6,1 @@',
            ' six',
            '-seven',
            '@@ -9,0 +9,1 @@',
            '+ten',
        ]);

        $this->assertSame($before, PatchSummary::before($after, $diff));
        $this->assertNull(PatchSummary::before($after, "diff --git a/new.php b/new.php\nnew file mode 100644\n--- /dev/null\n+++ b/new.php\n@@ -0,0 +1 @@\n+one"));
    }
}
