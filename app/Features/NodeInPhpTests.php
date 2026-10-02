<?php

namespace App\Features;

/**
 * PHP tests a change adds that start Node (or another JavaScript runtime)
 * themselves, most often to render a screen. That is slow, breaks with
 * the build tools, and is not how a Laravel app tests its screens: a test
 * checks what a page gets with Inertia's assertInertia, or opens it in a
 * Pest browser test.
 *
 * Only the lines a change adds count. A test file counts when it starts a
 * process and names a JavaScript runtime as a command, both in its added
 * lines, so a test that only mentions Node in a string is let through.
 */
class NodeInPhpTests
{
    /**
     * The PHP test files the scan reads.
     */
    protected const FILES = '/^tests\/.*\.php$/';

    /**
     * A process being started.
     */
    protected const STARTS = '/new\s+(\\\\?Symfony\\\\Component\\\\Process\\\\)?Process\s*\(|Process::(run|start|pipe|pool|command|path|timeout|env|input)\b|\b(proc_open|shell_exec|passthru|popen|system|exec)\s*\(/';

    /**
     * A JavaScript runtime named as a command, quoted on its own or at the
     * start of a command line.
     */
    protected const RUNTIMES = '/[\'"](node|nodejs|npx|bun|bunx|deno|vite|vitest|tsx|ts-node)(\s[^\'"]*)?[\'"]/';

    /**
     * Find the test files whose added lines start a JavaScript runtime, at
     * the first line that starts a process.
     *
     * @return list<array{path: string, line: int}>
     */
    public static function found(?string $patch): array
    {
        $found = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (preg_match(self::FILES, $file['path']) !== 1 || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            $added = PatchSummary::addedLines($file['diff']);
            $start = null;
            $runtime = false;

            foreach ($added as $line) {
                $start ??= preg_match(self::STARTS, $line['text']) === 1 ? $line['line'] : null;
                $runtime = $runtime || preg_match(self::RUNTIMES, $line['text']) === 1;
            }

            if ($start !== null && $runtime) {
                $found[] = ['path' => $file['path'], 'line' => $start];
            }
        }

        return $found;
    }

    /**
     * Say what is wrong and how to fix it, for the coder that must fix it.
     *
     * @param  array{path: string, line: int}  $found
     */
    public static function finding(array $found): string
    {
        return __('Line :line of :path starts Node from a PHP test. That is slow, breaks when the build tools change, and is not how Laravel apps test their screens. Check what a page gets with Inertia\'s assertInertia (its component and props), or open it in a Pest browser test, and remove the script the test ran.', $found);
    }

    /**
     * Determine if the patch adds PHP tests, so a clean scan says something.
     */
    public static function scans(?string $patch): bool
    {
        foreach (PatchSummary::files($patch) as $file) {
            if (preg_match(self::FILES, $file['path']) === 1 && PatchSummary::addedLines($file['diff']) !== []) {
                return true;
            }
        }

        return false;
    }
}
