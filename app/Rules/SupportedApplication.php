<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The builder imports only the kind of application it knows how to change:
 * Laravel with Inertia and Vue. Anything else is refused with the reason,
 * before any copy is made.
 */
class SupportedApplication implements ValidationRule
{
    /**
     * The packages an application must require, by manifest.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_PACKAGES = [
        'composer.json' => ['laravel/framework', 'inertiajs/inertia-laravel'],
        'package.json' => ['vue', '@inertiajs/vue3'],
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! File::isDirectory($value)) {
            $fail(__('There is no folder at this path.'));

            return;
        }

        if (! File::isFile($value.'/artisan')) {
            $fail(__('This folder is not a Laravel app: it has no artisan file.'));

            return;
        }

        foreach (self::REQUIRED_PACKAGES as $manifest => $packages) {
            $contents = File::isFile("{$value}/{$manifest}") ? json_decode(File::get("{$value}/{$manifest}"), true) : null;

            if (! is_array($contents)) {
                $fail(__('This app has no readable :manifest.', ['manifest' => $manifest]));

                return;
            }

            $required = array_merge((array) ($contents['require'] ?? []), (array) ($contents['dependencies'] ?? []), (array) ($contents['devDependencies'] ?? []));
            $missing = array_values(array_diff($packages, array_keys($required)));

            if ($missing !== []) {
                $fail(__('I can only work on Laravel apps that use Inertia with Vue. This app does not use :packages.', ['packages' => implode(', ', $missing)]));

                return;
            }
        }
    }
}
