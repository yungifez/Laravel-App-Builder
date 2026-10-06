<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A postal code of one of the given regions. Case and extra spaces do not
 * matter; the PostalCode cast stores it uppercase with one inner space.
 */
class ValidPostalCode implements ValidationRule
{
    /**
     * The shape of a postal code in each region we know. Any other region
     * is checked loosely, as "any".
     */
    public const PATTERNS = [
        'CA' => '/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z] ?\d[ABCEGHJ-NPRSTV-Z]\d$/',
        'US' => '/^\d{5}(-\d{4})?$/',
        'GB' => '/^(GIR ?0AA|[A-PR-UWYZ](\d{1,2}|[A-HK-Y]\d(\d|[ABEHMNPRV-Y])?|\d[A-HJKPS-UW]) ?\d[ABD-HJLNP-UW-Z]{2})$/',
        'NG' => '/^\d{6}$/',
        'any' => '/^[A-Z0-9][A-Z0-9 -]{1,8}[A-Z0-9]$/',
    ];

    /**
     * @param  list<string>  $regions  ISO 3166 codes, or "any"
     */
    public function __construct(private array $regions = ['any']) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = is_string($value) || is_int($value) ? strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $value))) : '';

        foreach ($this->regions as $region) {
            if (preg_match(self::PATTERNS[$region] ?? self::PATTERNS['any'], $code) === 1) {
                return;
            }
        }

        $fail('The :attribute must be a valid postal code.')->translate();
    }
}
