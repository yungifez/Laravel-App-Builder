<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Run the JavaScript package lookup in a folder, with a stand-in npm that
 * keeps where it ran and what it was given. Which advisories npm then
 * counts is npm's own work, and needs its registry.
 *
 * @param  array<string, string>  $files
 * @return array{app: string, seen: string, listing: list<SplFileInfo>}
 */
function lookUpPackages(array $files): array
{
    $root = sys_get_temp_dir().'/builder-audit-'.Str::lower(Str::random(8));
    $app = "{$root}/app";
    $bin = "{$root}/bin";
    File::ensureDirectoryExists($bin);

    foreach (['package.json' => '{"name":"shop"}', ...$files] as $path => $contents) {
        File::ensureDirectoryExists(dirname("{$app}/{$path}"));
        File::put("{$app}/{$path}", $contents);
    }

    File::put("{$bin}/npm", "#!/bin/sh\necho \"\$PWD \$*\" > {$root}/seen\nls \"\$PWD\" >> {$root}/seen\n");
    chmod("{$bin}/npm", 0755);

    $step = collect(config('builder.verification.security.steps'))->firstWhere('report', 'npm');
    Process::path($app)->env(['PATH' => "{$bin}:".getenv('PATH')])->run($step['command'])->throw();

    $seen = File::get("{$root}/seen");
    $result = ['app' => $app, 'seen' => $seen, 'listing' => File::files($app)];
    File::deleteDirectory($root);

    return $result;
}

test('an app with a lock file is looked up by what it ships, in place', function () {
    $result = lookUpPackages(['package-lock.json' => '{}']);

    expect($result['seen'])->toStartWith("{$result['app']} audit --package-lock-only --omit=dev --json");
});

test('an app with only what npm installed is looked up by what it ships, on a copy', function () {
    $result = lookUpPackages(['node_modules/.package-lock.json' => '{}']);

    $lines = explode("\n", trim($result['seen']));
    expect($lines[0])->not->toStartWith($result['app'])
        ->and($lines[0])->toContain('audit --package-lock-only --omit=dev --json')
        ->and(array_slice($lines, 1))->toBe(['package-lock.json', 'package.json'])
        // Nothing is written into the app.
        ->and(array_map(fn ($file) => $file->getFilename(), $result['listing']))->toBe(['package.json']);
});

test('an app with no lock at all still asks npm, which says why it cannot look', function () {
    $result = lookUpPackages([]);

    expect($result['seen'])->toStartWith("{$result['app']} audit --package-lock-only --omit=dev --json");
});
