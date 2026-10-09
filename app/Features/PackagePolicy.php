<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * The packages a change adds to the app, read from its Composer and npm
 * lockfiles (architecture §12 and §13, policy v0). A package the change asks
 * for by name must be on the allowlist; every package it brings, asked for
 * or pulled in, must carry an allowed license and come from the public
 * registry. Known security problems are looked up apart, for the whole app.
 */
class PackagePolicy
{
    /**
     * A package the change asks for that is not on the allowlist.
     */
    public const UNLISTED = 'package_unlisted';

    /**
     * A new package whose license is not allowed, or that gives none.
     */
    public const LICENSE = 'package_license';

    /**
     * A new package that does not come from the public registry.
     */
    public const SOURCE = 'package_source';

    /**
     * The kinds the owner may say they want, such as a package they chose.
     */
    public const OWNED = [self::UNLISTED, self::LICENSE, self::SOURCE];

    /**
     * The lockfile of each package manager and the manifest beside it.
     */
    protected const LOCKFILES = [
        'composer.lock' => ['manager' => 'composer', 'manifest' => 'composer.json'],
        'package-lock.json' => ['manager' => 'npm', 'manifest' => 'package.json'],
    ];

    /**
     * Find the packages a patch adds, updates and removes, given a reader
     * of each file as the change leaves it, with the rules each one breaks.
     * Null when no lockfile in the patch changes a package. A lockfile
     * anywhere in the app counts, with the manifest in the same folder. A
     * changed lockfile whose earlier text cannot be rebuilt is not guessed
     * at. An updated package is checked again only where its license or
     * where it comes from changed: what the app already had stays as the
     * owner kept it.
     *
     * @param  array{composer: list<string>, npm: list<string>}  $allowed  Package name patterns, by manager
     * @param  list<string>  $licenses  SPDX license names
     * @param  array{composer: string, npm: string}  $registries  What marks a package from the public registry, by manager
     * @param  callable(string): ?string  $contents
     * @return array{changes: list<array{name: string, manager: string, from: string|null, to: string|null, direct: bool}>, problems: list<array{name: string, manager: string, version: string, at: string, direct: bool, license: list<string>, source: string|null, rules: list<string>}>}|null
     */
    public static function inPatch(?string $patch, array $allowed, array $licenses, array $registries, callable $contents): ?array
    {
        $changes = [];
        $problems = [];

        foreach (PatchSummary::files($patch) as $file) {
            $lockfile = self::LOCKFILES[basename($file['path'])] ?? null;

            if ($lockfile === null) {
                continue;
            }

            $deleted = str_contains($file['diff'], "\ndeleted file mode ");
            $after = $deleted ? '' : $contents($file['path']);

            if (! is_string($after)) {
                continue;
            }

            $isNew = str_contains($file['diff'], "\nnew file mode ") || str_contains($file['diff'], "\n--- /dev/null");
            $before = $isNew ? '' : PatchSummary::before($after, $file['diff']);

            // A lockfile that does not read as one is not taken to mean every
            // package went.
            if ($before === null || ! self::readable($after) || ! self::readable($before)) {
                continue;
            }

            $manager = $lockfile['manager'];
            $folder = dirname($file['path']) === '.' ? '' : dirname($file['path']).'/';
            $known = collect(self::packages($manager, $before))->keyBy('name');
            $now = collect(self::packages($manager, $after))->keyBy('name');
            $direct = [
                ...self::direct($manager, $before, $deleted ? null : $contents($folder.$lockfile['manifest'])),
                ...self::direct($manager, $after, $deleted ? null : $contents($folder.$lockfile['manifest'])),
            ];

            foreach ($known->diffKeys($now) as $name => $package) {
                $changes[] = ['name' => $name, 'manager' => $manager, 'from' => $package['version'], 'to' => null, 'direct' => in_array($name, $direct, true)];
            }

            foreach ($now as $name => $package) {
                $was = $known->get($name);

                if ($was !== null && $was['version'] === $package['version'] && $was['license'] === $package['license'] && $was['source'] === $package['source']) {
                    continue;
                }

                $asked = in_array($name, $direct, true);

                if ($was === null || $was['version'] !== $package['version']) {
                    $changes[] = ['name' => $name, 'manager' => $manager, 'from' => $was['version'] ?? null, 'to' => $package['version'], 'direct' => $asked];
                }

                $rules = array_values(array_filter([
                    $was === null && $asked && ! Str::is($allowed[$manager], $name) ? self::UNLISTED : null,
                    ($was === null || $was['license'] !== $package['license']) && ! self::licensed($package['license'], $licenses) ? self::LICENSE : null,
                    ($was === null || $was['source'] !== $package['source']) && ! self::registered($package['source'], $registries[$manager]) ? self::SOURCE : null,
                ]));

                if ($rules !== []) {
                    $problems[] = [...$package, 'manager' => $manager, 'at' => $file['path'], 'direct' => $asked, 'rules' => $rules];
                }
            }
        }

        return $changes !== [] || $problems !== [] ? ['changes' => $changes, 'problems' => $problems] : null;
    }

    /**
     * Whether lockfile text reads as a lockfile. Empty text is a lockfile
     * that is not there.
     */
    protected static function readable(string $lockfile): bool
    {
        return $lockfile === '' || is_array(json_decode($lockfile, true));
    }

    /**
     * Whether a package comes from the public registry. A package with no
     * address of its own, such as one npm links, is not installed from
     * anywhere.
     */
    protected static function registered(?string $source, string $registry): bool
    {
        return $source === null || str_starts_with($source, $registry);
    }

    /**
     * Read the packages a lockfile installs, once each by name. Empty when
     * it cannot be read. An npm package linked from the app's own folders
     * or bundled inside another is not installed from anywhere.
     *
     * @return list<array{name: string, version: string, license: list<string>, source: string|null}>
     */
    public static function packages(string $manager, string $lockfile): array
    {
        $lock = json_decode($lockfile, true);

        if (! is_array($lock)) {
            return [];
        }

        $packages = [];

        if ($manager === 'composer') {
            foreach ([...$lock['packages'] ?? [], ...$lock['packages-dev'] ?? []] as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                    continue;
                }

                // Packagist marks the packages it serves with where to count
                // downloads; any other repository leaves it out.
                $packagist = is_string($package['notification-url'] ?? null) ? $package['notification-url'] : null;

                $packages[$package['name']] ??= [
                    'name' => $package['name'],
                    'version' => (string) ($package['version'] ?? ''),
                    'license' => array_values(array_filter((array) ($package['license'] ?? []), is_string(...))),
                    'source' => $packagist ?? self::text($package['dist']['url'] ?? $package['source']['url'] ?? null) ?? __('a repository it does not name'),
                ];
            }

            return array_values($packages);
        }

        foreach ($lock['packages'] ?? [] as $path => $package) {
            if ($path === '' || ! is_array($package) || ($package['link'] ?? false) || ($package['inBundle'] ?? false)) {
                continue;
            }

            $name = is_string($package['name'] ?? null) ? $package['name'] : Str::afterLast((string) $path, 'node_modules/');

            $packages[$name] ??= [
                'name' => $name,
                'version' => (string) ($package['version'] ?? ''),
                'license' => is_string($package['license'] ?? null) ? [$package['license']] : [],
                'source' => self::text($package['resolved'] ?? null),
            ];
        }

        return array_values($packages);
    }

    /**
     * Get the findings: one for each rule each new package breaks, less
     * those the owner said they want.
     *
     * @param  array{problems: list<array{name: string, manager: string, rules: list<string>}>}|null  $packages
     * @param  list<string>  $accepted  Identities the owner said they want
     * @return list<array{kind: string, subject: string}>
     */
    public static function findings(?array $packages, array $accepted = []): array
    {
        $findings = [];

        foreach ($packages['problems'] ?? [] as $package) {
            foreach ($package['rules'] as $rule) {
                $findings[] = ['kind' => $rule, 'subject' => "{$package['manager']}:{$package['name']}"];
            }
        }

        return array_values(array_filter($findings, fn (array $finding) => ! in_array(self::identity($finding), $accepted, true)));
    }

    /**
     * Name a finding the same way each time the checks run.
     *
     * @param  array{kind: string, subject: string}  $finding
     */
    public static function identity(array $finding): string
    {
        return "{$finding['kind']}|{$finding['subject']}";
    }

    /**
     * Say what a finding is, for the agent that sends the change back.
     *
     * @param  array{kind: string, subject: string}  $finding
     * @param  array{problems: list<array{name: string, manager: string, version: string, at: string, license: list<string>, source: string|null}>}  $packages
     */
    public static function finding(array $finding, array $packages): string
    {
        $package = collect($packages['problems'])->first(fn (array $package) => "{$package['manager']}:{$package['name']}" === $finding['subject']);
        $license = $package['license'] ?? [];
        $values = [
            'package' => self::name($finding['subject']),
            'version' => $package['version'] ?? '',
            'at' => $package['at'] ?? '',
            'license' => $license === [] ? __('no license') : implode(__(' or '), $license),
            'source' => $package['source'] ?? '',
        ];

        return match ($finding['kind']) {
            self::UNLISTED => __('The change adds :package (:at), which is not on the list of packages this app may add. Use Laravel or a package the app already has instead. If the owner wants this package, ask them to keep it.', $values),
            self::LICENSE => __('The change brings in :package :version (:at) with :license, which is not on the list of licenses this app may use. Use another package. If the owner accepts the license, ask them to keep it.', $values),
            default => __('The change installs :package :version (:at) from :source, not from the public registry. Install it from the public registry. If the owner trusts this source, ask them to keep it.', $values),
        };
    }

    /**
     * Name a package for the owner and the coder: "spatie/laravel-pdf" for
     * composer:spatie/laravel-pdf.
     */
    public static function name(string $subject): string
    {
        return Str::after($subject, ':');
    }

    /**
     * Read the packages the app asks for by name: from the manifest for
     * Composer, and from the lockfile's own copy of it for npm.
     *
     * @return list<string>
     */
    protected static function direct(string $manager, string $lockfile, ?string $manifest): array
    {
        $json = json_decode($manager === 'npm' ? $lockfile : (string) $manifest, true);
        $root = $manager === 'npm' ? ($json['packages'][''] ?? null) : $json;

        if (! is_array($root)) {
            return [];
        }

        $keys = $manager === 'npm' ? ['dependencies', 'devDependencies', 'optionalDependencies'] : ['require', 'require-dev'];

        return array_values(array_unique(array_merge(...array_map(fn (string $key) => is_array($root[$key] ?? null) ? array_map(strval(...), array_keys($root[$key])) : [], $keys))));
    }

    /**
     * Whether a package's license is allowed. Composer lists choices; npm
     * gives one SPDX expression. One allowed choice is enough, and each
     * part joined by AND must be allowed. No license is never allowed.
     *
     * @param  list<string>  $license
     * @param  list<string>  $allowed
     */
    protected static function licensed(array $license, array $allowed): bool
    {
        foreach ($license as $expression) {
            foreach (preg_split('/\s+OR\s+/i', trim($expression, " \t()")) ?: [] as $choice) {
                $parts = preg_split('/\s+AND\s+/i', trim($choice, " \t()")) ?: [];

                if ($parts !== [] && array_diff(array_map(fn (string $part) => trim($part, " \t()"), $parts), $allowed) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    protected static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
