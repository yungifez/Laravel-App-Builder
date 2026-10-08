<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A project's source must be an existing directory inside one of the roots
 * the operator allows (builder.projects.roots), and must not contain the
 * control plane itself. Without allowed roots, no path is accepted.
 */
class ProjectSourcePath implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = is_string($value) ? self::resolve($value) : null;

        if ($path === null || ! is_dir($path)) {
            $fail(__('The :attribute must be an existing directory.'));

            return;
        }

        $inside = fn (string $child, string $parent) => $child === $parent || Str::startsWith($child, rtrim($parent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

        if ($inside((string) realpath(base_path()), $path)) {
            $fail(__('The :attribute must not contain the builder itself.'));

            return;
        }

        foreach (self::roots() as $root) {
            if ($inside($path, $root)) {
                return;
            }
        }

        $fail(__('The :attribute must be inside a directory the builder allows projects in.'));
    }

    /**
     * Resolve a path to its canonical absolute form; relative paths are
     * resolved from the application's base path.
     */
    public static function resolve(string $path): ?string
    {
        $absolute = Str::startsWith($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        return realpath($absolute) ?: null;
    }

    /**
     * Get the allowed roots that exist, resolved.
     *
     * @return list<string>
     */
    protected static function roots(): array
    {
        /** @var list<string> $roots */
        $roots = config('builder.projects.roots', []);

        return array_values(array_filter(array_map(self::resolve(...), $roots)));
    }
}
