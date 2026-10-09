<?php

use App\Features\ProductionCaches;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

const STAND_IN_ARTISAN = <<<'PHP'
<?php

// Stands in for artisan: notes each command, and fails a cache named in
// $FAIL with what Laravel prints for it, or with $SAID when it is set.
file_put_contents(getenv('SEEN'), $argv[1]."\n", FILE_APPEND);
[$cache, $verb] = explode(':', $argv[1]);

if ($verb === 'cache' && in_array($cache, explode(',', (string) getenv('FAIL')), true)) {
    echo getenv('SAID') !== '' ? getenv('SAID') : "\n   LogicException \n\n  Unable to prepare route [a-two] for serialization. Another route has already been assigned name [dupe].\n\n  at vendor/laravel/framework/src/Illuminate/Routing/AbstractRouteCollection.php:257\n";
    exit(1);
}

echo "\n   INFO  Cached successfully.\n";
PHP;

/**
 * Run the check's script in a folder with a stand-in artisan.
 *
 * @return array{exit: int, output: string, seen: list<string>}
 */
function runCachesScript(string $fail = '', string $said = ''): array
{
    $dir = sys_get_temp_dir().'/production-caches-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/artisan", STAND_IN_ARTISAN);

    $result = Process::path($dir)
        ->env(['FAIL' => $fail, 'SAID' => $said, 'SEEN' => "{$dir}/seen"])
        ->run(['sh', '-c', ProductionCaches::script()]);

    $run = [
        'exit' => (int) $result->exitCode(),
        'output' => $result->output(),
        'seen' => array_values(array_filter(explode("\n", (string) @file_get_contents("{$dir}/seen")))),
    ];
    File::deleteDirectory($dir);

    return $run;
}

it('prepares each cache a host would and clears them again before and after, printing nothing when all work', function () {
    $run = runCachesScript();
    $clears = ['config:clear', 'route:clear', 'event:clear', 'view:clear'];

    expect([$run['exit'], $run['output']])->toBe([0, ''])
        ->and($run['seen'])->toBe([...$clears, 'config:cache', 'route:cache', 'event:cache', 'view:cache', ...$clears]);
});

it('prints one line for each cache that fails, without the trace, and still clears them all', function () {
    $run = runCachesScript('route,view');

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toBe(implode("\n", [
            'route: Unable to prepare route [a-two] for serialization. Another route has already been assigned name [dupe].',
            'view: Unable to prepare route [a-two] for serialization. Another route has already been assigned name [dupe].',
        ])."\n")
        ->and(array_slice($run['seen'], -4))->toBe(['config:clear', 'route:clear', 'event:clear', 'view:clear']);
});

it('says what an unknown failure printed first, or that it said nothing', function () {
    $error = runCachesScript('config', "\n   ERROR  Command \"config:cache\" is not defined.\n");
    $plain = runCachesScript('event', "Segmentation fault\nmore\n");
    $silent = runCachesScript('event', ' ');

    expect($error['output'])->toBe("config: Command \"config:cache\" is not defined.\n")
        ->and($plain['output'])->toBe("event: Segmentation fault\n")
        ->and($silent['output'])->toBe("event: it stopped without saying why\n");
});

it('says each kind of problem once in the owner\'s words, and a plain line for one it does not know', function () {
    expect(ProductionCaches::meaning([
        'route: Unable to prepare route [a-two] for serialization. Another route has already been assigned name [dupe].',
        'route: Unable to prepare route [b] for serialization. Another route has already been assigned name [x].',
        'config: Your configuration files could not be serialized because the value at "zz.f" is non-serializable.',
    ]))->toBe('two pages share a name, and one of its settings cannot be prepared ahead of time')
        ->and(ProductionCaches::meaning(['event: Segmentation fault']))->toBe('it could not be prepared for going online');
});
