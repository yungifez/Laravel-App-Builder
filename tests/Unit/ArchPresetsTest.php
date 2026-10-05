<?php

use App\Features\ArchPresets;
use App\Workspaces\Drivers\CopyExclusions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

const STAND_IN_PEST = <<<'PHP'
<?php

// Stands in for Pest: keeps the test it was given, then writes a JUnit
// report as $REPORT says, or none, and ends as $CODE says.
$args = implode(' ', array_slice($argv, 1));
preg_match('/--log-junit=(\S+)/', $args, $junit);
preg_match('/--test-directory=(\S+)/', $args, $directory);
file_put_contents(getenv('SEEN'), $args."\n".file_get_contents($directory[1].'/ArchTest.php'));
echo "Pest said this.\n";

if (getenv('REPORT') !== '') {
    file_put_contents($junit[1], getenv('REPORT'));
}

exit((int) getenv('CODE'));
PHP;

/**
 * Run the check's script in a folder with a stand-in Pest.
 *
 * @return array{exit: int, output: string, seen: string, left: bool}
 */
function runArchScript(string $report, int $code): array
{
    $dir = sys_get_temp_dir().'/arch-presets-'.uniqid();
    File::ensureDirectoryExists("{$dir}/vendor/bin");
    File::put("{$dir}/vendor/bin/pest", STAND_IN_PEST);

    $result = Process::path($dir)
        ->env(['REPORT' => $report, 'CODE' => (string) $code, 'SEEN' => "{$dir}/seen"])
        ->run(['sh', '-c', ArchPresets::script()]);

    $run = [
        'exit' => (int) $result->exitCode(),
        'output' => $result->output(),
        'seen' => (string) @file_get_contents("{$dir}/seen"),
        'left' => file_exists("{$dir}/.builder"),
    ];
    File::deleteDirectory($dir);

    return $run;
}

it('runs Laravel\'s presets and prints the first line of each problem, ending as Pest ended', function () {
    $report = '<?xml version="1.0"?><testsuites><testsuite name="ArchTest" file="ArchTest.php">'
        .'<testcase name="preset → laravel"><failure type="ArchExpectationFailedException">preset → laravel Expecting \'app/Http/Controllers/RoomController.php\' not to have public methods besides \'index\'.'."\nat app/Http/Controllers/RoomController.php:18</failure></testcase>"
        .'<testcase name="preset → security"/></testsuite></testsuites>';

    $run = runArchScript($report, 1);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toBe("preset → laravel Expecting 'app/Http/Controllers/RoomController.php' not to have public methods besides 'index'.\n")
        ->and($run['seen'])->toContain('--test-directory=.builder/arch .builder/arch/ArchTest.php --log-junit=.builder/arch/report.xml')
        ->and($run['seen'])->toContain("arch()->preset()->laravel();\narch()->preset()->security();")
        ->and($run['left'])->toBeFalse();
});

it('passes when Pest finds nothing, and says what Pest said when it wrote no report', function () {
    $clean = runArchScript('<?xml version="1.0"?><testsuites><testsuite name="ArchTest"><testcase name="preset → laravel"/></testsuite></testsuites>', 0);
    $crashed = runArchScript('', 255);

    expect([$clean['exit'], $clean['output'], $clean['left']])->toBe([0, '', false])
        ->and([$crashed['exit'], $crashed['output'], $crashed['left']])->toBe([255, "Pest said this.\n", false]);
});

it('keeps its folder where no change can carry it: workspaces never get it and git apply skips it', function () {
    // A run stopped before it tidies up may leave the folder behind, in a
    // workspace that is thrown away; it still never reaches the app.
    $dir = sys_get_temp_dir().'/arch-apply-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/change.patch", implode("\n", [
        'diff --git a/'.ArchPresets::DIRECTORY.'/ArchTest.php b/'.ArchPresets::DIRECTORY.'/ArchTest.php',
        'new file mode 100644',
        '--- /dev/null',
        '+++ b/'.ArchPresets::DIRECTORY.'/ArchTest.php',
        '@@ -0,0 +1 @@',
        '+<?php',
        '',
    ]));

    Process::path($dir)->run(['git', 'apply', ...CopyExclusions::applyFlags(), 'change.patch'])->throw();

    expect(file_exists("{$dir}/".ArchPresets::DIRECTORY))->toBeFalse()
        ->and(collect(CopyExclusions::PATHS)->contains(fn (string $path) => str_starts_with('./'.ArchPresets::DIRECTORY, $path.'/')))->toBeTrue();
    File::deleteDirectory($dir);
});
