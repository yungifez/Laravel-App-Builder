<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An ISBN of the given lengths whose check digit is right. Spaces and
 * hyphens may be typed; the Isbn cast removes them.
 */
class ValidIsbn implements ValidationRule
{
    /**
     * @param  list<int>  $variants  The lengths allowed: 10, 13 or both
     */
    public function __construct(private array $variants = [10, 13]) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isbn = is_string($value) || is_int($value) ? strtoupper((string) preg_replace('/[\s-]/', '', (string) $value)) : '';

        $valid = (in_array(10, $this->variants, true) && self::isbn10($isbn))
            || (in_array(13, $this->variants, true) && self::isbn13($isbn));

        if (! $valid) {
            $fail('The :attribute must be a valid ISBN.')->translate();
        }
    }

    /**
     * Nine digits and a check digit (0-9 or X), weighted 10 down to 1.
     */
    private static function isbn10(string $isbn): bool
    {
        if (preg_match('/^\d{9}[\dX]$/', $isbn) !== 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $sum += ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]) * (10 - $i);
        }

        return $sum % 11 === 0;
    }

    /**
     * Thirteen digits starting 978 or 979, weighted 1 and 3 in turn.
     */
    private static function isbn13(string $isbn): bool
    {
        if (preg_match('/^97[89]\d{10}$/', $isbn) !== 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $sum % 10 === 0;
    }
}
