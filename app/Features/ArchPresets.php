<?php

namespace App\Features;

/**
 * Laravel's own structure rules and its security rules, run as Pest's
 * architecture presets on the app (architecture §12). Pest stops each
 * preset at the first problem it finds, so the check prints one line for
 * each: the problem that preset found first. When the check fails, the
 * same lines from the starting commit tell the change's problems from the
 * app's older ones. A new problem found after an older one in the same
 * preset stays hidden until the older one is fixed.
 */
class ArchPresets
{
    /**
     * The check's name, as its result is kept.
     */
    public const CHECK = 'Laravel structure';

    /**
     * Where the throwaway test is written. Workspaces never get this folder
     * from the app, and git apply skips it, so the test cannot reach a
     * change, a commit or the owner's repository.
     */
    public const DIRECTORY = '.builder/arch';

    /**
     * The file only Pest 3 and later ship, with the presets.
     */
    public const NEEDS = 'vendor/pestphp/pest/src/ArchPresets/Laravel.php';

    /**
     * Build the check's shell script. It writes the test, runs the presets,
     * prints the first line of each problem from the JUnit report (or what
     * Pest said, when it wrote none) and removes the folder however it ends.
     */
    public static function script(): string
    {
        $directory = self::DIRECTORY;
        $test = "<?php\n\narch()->preset()->laravel();\narch()->preset()->security();\n";
        $report = <<<'PHP'
            $report = @simplexml_load_file($argv[1]);
            if ($report === false) { readfile($argv[2]); exit; }
            foreach ($report->xpath('//testcase') ?: [] as $case) {
                foreach ([$case->failure, $case->error] as $problem) {
                    if ((string) $problem !== '') { echo strtok(trim((string) $problem), "\n"), "\n"; }
                }
            }
            PHP;

        return implode("\n", [
            "d='{$directory}'",
            'rm -rf "$d" && mkdir -p "$d" || exit 1',
            "trap 'rm -rf \"\$d\"; rmdir \"\$(dirname \"\$d\")\" 2>/dev/null' EXIT",
            "trap 'exit 143' INT TERM HUP",
            'printf %s '.escapeshellarg($test).' > "$d/ArchTest.php"',
            'php vendor/bin/pest --test-directory="$d" "$d/ArchTest.php" --log-junit="$d/report.xml" > "$d/output.txt" 2>&1',
            'code=$?',
            'php -r '.escapeshellarg($report).' "$d/report.xml" "$d/output.txt"',
            'exit $code',
        ]);
    }
}
