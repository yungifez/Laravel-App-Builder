<?php

namespace Tests\Feature\Runs;

use App\Runs\ReviewDiff;
use Tests\TestCase;

/**
 * The reviewer reads every file of a change whole, or is told by name which
 * files it does not see: a long first version is never cut off midway.
 */
class ReviewDiffTest extends TestCase
{
    public function test_a_change_within_the_limit_is_shown_whole(): void
    {
        $patch = $this->added('routes/web.php', 5)."\n".$this->added('tests/Feature/BookTest.php', 5);

        $laid = ReviewDiff::lay($patch, 10000);

        $this->assertSame($patch, $laid['diff']);
        $this->assertSame([], $laid['left_out']);
    }

    public function test_lock_files_and_deleted_files_are_named_so_the_code_is_shown_whole(): void
    {
        $code = [$this->added('app/Models/Book.php', 20), $this->added('routes/web.php', 10), $this->added('tests/Feature/BookTest.php', 30)];
        // As run 44: the lock file and a deleted page come before the routes and the tests.
        $patch = implode("\n", [$code[0], $this->added('package-lock.json', 3000), $this->deleted('resources/js/pages/Welcome.vue', 900), ...array_slice($code, 1)]);
        $limit = 5000;
        $this->assertGreaterThan($limit, mb_strlen($patch));

        $laid = ReviewDiff::lay($patch, $limit);

        $this->assertSame(implode("\n", $code), $laid['diff']);
        $this->assertSame([
            'package-lock.json: the package manager’s lock file (+3000 −0 lines), not shown. The packages the change asks for are in its manifest.',
            'resources/js/pages/Welcome.vue: deleted (900 lines).',
        ], $laid['left_out']);
    }

    public function test_code_still_too_long_leaves_out_whole_files_by_name_and_never_cuts_one(): void
    {
        $tests = $this->added('tests/Feature/BookTest.php', 40);
        $routes = $this->added('routes/web.php', 10);
        $page = $this->added('resources/js/pages/books/Index.vue', 400);
        $model = $this->added('app/Models/Book.php', 20);
        $patch = implode("\n", [$page, $model, $routes, $tests]);

        $laid = ReviewDiff::lay($patch, mb_strlen("{$model}\n{$routes}\n{$tests}\n") + 10);

        // Tests, routes and app code are kept; the page goes, by name.
        $this->assertSame("{$model}\n{$routes}\n{$tests}", $laid['diff']);
        $this->assertSame(['resources/js/pages/books/Index.vue (+400 −0 lines): left out for length.'], $laid['left_out']);
        $this->assertStringNotContainsString('Index.vue', $laid['diff']);

        // A file longer than the limit by itself: its start, said to be one.
        $alone = ReviewDiff::lay($page, 300);
        $this->assertTrue(str_starts_with($alone['diff'], mb_substr($page, 0, 300)));
        $this->assertStringEndsWith('(the rest of resources/js/pages/books/Index.vue is left out for length)', $alone['diff']);
        $this->assertSame([], $alone['left_out']);
    }

    protected function added(string $path, int $lines): string
    {
        $body = implode("\n", array_map(fn (int $line) => "+line {$line} of {$path}", range(1, $lines)));

        return "diff --git a/{$path} b/{$path}\nnew file mode 100644\nindex 0000000..1111111\n--- /dev/null\n+++ b/{$path}\n@@ -0,0 +1,{$lines} @@\n{$body}";
    }

    protected function deleted(string $path, int $lines): string
    {
        $body = implode("\n", array_map(fn (int $line) => "-line {$line} of {$path}", range(1, $lines)));

        return "diff --git a/{$path} b/{$path}\ndeleted file mode 100644\nindex 1111111..0000000\n--- a/{$path}\n+++ /dev/null\n@@ -1,{$lines} +0,0 @@\n{$body}";
    }
}
