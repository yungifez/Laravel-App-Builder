<?php

use App\Features\PackagePolicy;
use Tests\TestCase;

uses(TestCase::class);

const PACKAGE_ALLOWED = ['composer' => ['laravel/*'], 'npm' => ['vue', '@vue/*']];
const PACKAGE_LICENSES = ['MIT', 'Apache-2.0', 'BSD-3-Clause'];
const PACKAGE_REGISTRIES = ['composer' => 'https://packagist.org/', 'npm' => 'https://registry.npmjs.org/'];

/**
 * A Composer package as composer.lock records it.
 *
 * @param  list<string>  $license
 * @return array<string, mixed>
 */
function composerPackage(string $name, array $license = ['MIT'], bool $packagist = true): array
{
    return [
        'name' => $name,
        'version' => 'v1.0.0',
        'dist' => ['type' => 'zip', 'url' => $packagist ? "https://api.github.com/repos/{$name}/zipball/abc" : 'https://git.example.com/'.$name.'.zip'],
        'license' => $license,
        ...($packagist ? ['notification-url' => 'https://packagist.org/downloads/'] : []),
    ];
}

/**
 * A lockfile as text, one entry per line, so a patch can change one line.
 *
 * @param  array<string, mixed>  $lock
 */
function lockText(array $lock): string
{
    return json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

/**
 * A patch that rewrites a whole file from the before text to the after
 * text, as git writes it.
 */
function rewritePatch(string $path, string $before, string $after): string
{
    $old = explode("\n", rtrim($before, "\n"));
    $new = explode("\n", rtrim($after, "\n"));

    return implode("\n", [
        "diff --git a/{$path} b/{$path}",
        "--- a/{$path}",
        "+++ b/{$path}",
        '@@ -1,'.count($old).' +1,'.count($new).' @@',
        ...array_map(fn (string $line) => "-{$line}", $old),
        ...array_map(fn (string $line) => "+{$line}", $new),
    ])."\n";
}

it('finds the Composer packages a change adds that its manifest asks for off the allowlist, or that break the license or source rules', function () {
    $before = lockText(['packages' => [composerPackage('laravel/framework')], 'packages-dev' => []]);
    $after = lockText(['packages' => [
        composerPackage('laravel/framework'),
        composerPackage('laravel/cashier'),
        composerPackage('acme/pdf', ['proprietary']),
        composerPackage('acme/helpers', ['MIT', 'GPL-3.0-only']),
        composerPackage('acme/private', packagist: false),
    ], 'packages-dev' => []]);
    $files = [
        'composer.lock' => $after,
        'composer.json' => (string) json_encode(['require' => ['laravel/framework' => '^13.0', 'laravel/cashier' => '^16.0', 'acme/pdf' => '^1.0']]),
    ];

    $packages = PackagePolicy::inPatch(rewritePatch('composer.lock', $before, $after), PACKAGE_ALLOWED, PACKAGE_LICENSES, PACKAGE_REGISTRIES, fn (string $path) => $files[$path] ?? null);

    expect($packages['added'])->toBe(4)
        ->and(array_map(fn (array $package) => [$package['name'], $package['direct'], $package['rules']], $packages['problems']))->toBe([
            ['acme/pdf', true, [PackagePolicy::UNLISTED, PackagePolicy::LICENSE]],
            ['acme/private', false, [PackagePolicy::SOURCE]],
        ])
        ->and($packages['problems'][1]['source'])->toBe('https://git.example.com/acme/private.zip');
});

it('reads an npm lockfile anywhere in the app: what it asks for from its root entry, and each license as an SPDX expression', function () {
    $lock = fn (array $packages) => lockText(['lockfileVersion' => 3, 'packages' => $packages]);
    $entry = fn (string $name, ?string $license = 'MIT', string $from = 'https://registry.npmjs.org/') => ['version' => '1.0.0', 'resolved' => "{$from}{$name}/-/x.tgz", ...($license === null ? [] : ['license' => $license])];
    $before = $lock(['' => ['dependencies' => ['vue' => '^3.5']], 'node_modules/vue' => $entry('vue')]);
    $after = $lock([
        '' => ['dependencies' => ['vue' => '^3.5', 'left-pad' => '^1.0', '@vue/shared' => '^3.5'], 'devDependencies' => ['sketchy' => '^1.0']],
        'node_modules/vue' => $entry('vue'),
        'node_modules/@vue/shared' => $entry('@vue/shared', '(MIT OR GPL-2.0)'),
        'node_modules/left-pad' => $entry('left-pad', 'WTFPL'),
        'node_modules/sketchy' => $entry('sketchy', null, 'https://npm.example.com/'),
        'node_modules/@vue/shared/node_modules/both' => $entry('both', 'MIT AND Apache-2.0'),
        'node_modules/half' => $entry('half', 'MIT AND GPL-3.0'),
        'node_modules/local' => ['resolved' => 'packages/local', 'link' => true],
    ]);
    $files = ['frontend/package-lock.json' => $after];

    $packages = PackagePolicy::inPatch(rewritePatch('frontend/package-lock.json', $before, $after), PACKAGE_ALLOWED, PACKAGE_LICENSES, PACKAGE_REGISTRIES, fn (string $path) => $files[$path] ?? null);

    expect($packages['added'])->toBe(5)
        ->and(array_map(fn (array $package) => [$package['name'], $package['at'], $package['rules']], $packages['problems']))->toBe([
            ['left-pad', 'frontend/package-lock.json', [PackagePolicy::UNLISTED, PackagePolicy::LICENSE]],
            ['sketchy', 'frontend/package-lock.json', [PackagePolicy::UNLISTED, PackagePolicy::LICENSE, PackagePolicy::SOURCE]],
            ['half', 'frontend/package-lock.json', [PackagePolicy::LICENSE]],
        ]);
});

it('says nothing when no lockfile adds a package, or a lockfile cannot be read or rebuilt', function () {
    $lock = lockText(['packages' => [composerPackage('laravel/framework')]]);
    $bumped = str_replace('v1.0.0', 'v1.1.0', $lock);
    $read = fn (string $text) => fn (string $path) => $path === 'composer.lock' ? $text : null;

    expect(PackagePolicy::inPatch(rewritePatch('composer.lock', $lock, $bumped), PACKAGE_ALLOWED, PACKAGE_LICENSES, PACKAGE_REGISTRIES, $read($bumped)))->toBeNull()
        ->and(PackagePolicy::inPatch(rewritePatch('composer.lock', $lock, '{'), PACKAGE_ALLOWED, PACKAGE_LICENSES, PACKAGE_REGISTRIES, $read('{')))->toBeNull()
        // Hunks out of order: the earlier text cannot be rebuilt.
        ->and(PackagePolicy::inPatch("diff --git a/composer.lock b/composer.lock\n--- a/composer.lock\n+++ b/composer.lock\n@@ -5,1 +5,1 @@\n-a\n+b\n@@ -1,1 +1,1 @@\n-c\n+d\n", PACKAGE_ALLOWED, PACKAGE_LICENSES, PACKAGE_REGISTRIES, $read(lockText(['packages' => [composerPackage('acme/pdf', [])]]))))->toBeNull()
        ->and(PackagePolicy::inPatch(null, PACKAGE_ALLOWED, PACKAGE_LICENSES, PACKAGE_REGISTRIES, $read($lock)))->toBeNull();
});

it('finds each rule each package breaks, less what the owner accepted, and tells the coder what to do', function () {
    $packages = ['added' => 3, 'problems' => [
        ['name' => 'acme/pdf', 'manager' => 'composer', 'version' => 'v1.0.0', 'at' => 'composer.lock', 'direct' => true, 'license' => [], 'source' => 'https://packagist.org/downloads/', 'rules' => [PackagePolicy::UNLISTED, PackagePolicy::LICENSE]],
        ['name' => 'sketchy', 'manager' => 'npm', 'version' => '1.0.0', 'at' => 'package-lock.json', 'direct' => false, 'license' => ['MIT'], 'source' => 'https://npm.example.com/sketchy.tgz', 'rules' => [PackagePolicy::SOURCE]],
    ]];
    $findings = PackagePolicy::findings($packages);

    expect($findings)->toBe([
        ['kind' => PackagePolicy::UNLISTED, 'subject' => 'composer:acme/pdf'],
        ['kind' => PackagePolicy::LICENSE, 'subject' => 'composer:acme/pdf'],
        ['kind' => PackagePolicy::SOURCE, 'subject' => 'npm:sketchy'],
    ])->and(PackagePolicy::findings($packages, [PackagePolicy::identity($findings[0])]))->toBe([$findings[1], $findings[2]])
        ->and(PackagePolicy::findings(null))->toBe([])
        ->and(PackagePolicy::finding($findings[0], $packages))->toBe('The change adds acme/pdf (composer.lock), which is not on the list of packages this app may add. Use Laravel or a package the app already has instead. If the owner wants this package, ask them to keep it.')
        ->and(PackagePolicy::finding($findings[1], $packages))->toContain('acme/pdf v1.0.0 (composer.lock) with no license')
        ->and(PackagePolicy::finding($findings[2], $packages))->toContain('sketchy 1.0.0 (package-lock.json) from https://npm.example.com/sketchy.tgz');
});
