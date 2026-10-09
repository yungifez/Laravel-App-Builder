<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The builder imports any Laravel application, whatever its screens are
 * made with (see App\Projects\Frontend). Anything else is refused with the
 * reason, before any copy is made.
 */
class SupportedApplication implements ValidationRule
{
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

        $composer = File::isFile($value.'/composer.json') ? json_decode(File::get($value.'/composer.json'), true) : null;

        if (! is_array($composer)) {
            $fail(__('This app has no readable composer.json.'));

            return;
        }

        if (! array_key_exists('laravel/framework', (array) ($composer['require'] ?? []))) {
            $fail(__('I can only work on Laravel apps. This app does not use laravel/framework.'));
        }
    }
}
