<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * The files the checks themselves depend on (direction 33, §12). A change
 * that edits them could pass its checks by changing how they run: a
 * `build` script that does nothing, a test config that finds no tests. So
 * verification refuses such a change before it runs anything, on its own
 * terms rather than trusting each driver to have put the files back.
 *
 * Package manifests are allowed to change, since adding a package is
 * ordinary work; only their `scripts` are checks' inputs.
 */
class ProtectedInputs
{
    /**
     * The manifests whose `scripts` the setup and checks run.
     */
    public const MANIFESTS = ['composer.json', 'package.json'];

    /**
     * List the touched paths that are, or are inside, a protected path.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $protected
     * @return list<string>
     */
    public static function touched(array $paths, array $protected): array
    {
        return array_values(array_filter($paths, function (string $path) use ($protected): bool {
            foreach ($protected as $guarded) {
                $guarded = Str::lower(trim($guarded, '/'));
                $lower = Str::lower(Str::chopStart(ltrim($path, '/'), './'));

                if ($guarded !== '' && ($lower === $guarded || Str::startsWith($lower, $guarded.'/'))) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Determine if a manifest's `scripts` differ between two versions of
     * it. A file that is missing or cannot be read has no scripts.
     */
    public static function scriptsChanged(?string $before, ?string $after): bool
    {
        return self::scripts($before) != self::scripts($after);
    }

    /**
     * @return array<mixed>
     */
    protected static function scripts(?string $manifest): array
    {
        $decoded = $manifest === null ? null : json_decode($manifest, true);

        return is_array($decoded) && is_array($decoded['scripts'] ?? null) ? $decoded['scripts'] : [];
    }
}
