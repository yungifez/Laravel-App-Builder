<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Store an amount as whole minor units (cents for dollars), so sums never
 * drift, and give it back as the decimal people type ("12.50" in USD is
 * stored as 1250). Show it with Number::currency().
 *
 * @implements CastsAttributes<string|null, string|int|float|null>
 */
class Money implements CastsAttributes
{
    /**
     * Currencies whose minor unit is not a hundredth. Every other one has
     * two decimal places.
     */
    public const MINOR_UNITS = [
        'JPY' => 0,
        'KRW' => 0,
        'BHD' => 3,
        'KWD' => 3,
        'OMR' => 3,
    ];

    /**
     * @param  string  $currency  An ISO 4217 code, or "per_record"
     * @param  string|null  $column  With "per_record", the column holding the record's currency
     */
    public function __construct(private string $currency, private ?string $column = null) {}

    /**
     * Get the number of decimal places of a currency.
     */
    public static function places(?string $currency): int
    {
        return self::MINOR_UNITS[strtoupper((string) $currency)] ?? 2;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $places = $this->placesFor($attributes);
        $minor = (int) $value;

        if ($places === 0) {
            return (string) $minor;
        }

        $digits = str_pad((string) abs($minor), $places + 1, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '').substr($digits, 0, -$places).'.'.substr($digits, -$places);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $places = $this->placesFor($attributes);
        $amount = is_float($value) ? number_format($value, $places, '.', '') : trim((string) $value);
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');

        // More places than the currency has are rounded, half up.
        $round = strlen($fraction) > $places && (int) $fraction[$places] >= 5;
        $minor = (int) ($whole.str_pad(substr($fraction, 0, $places), $places, '0')) + ($round ? 1 : 0);

        return $negative ? -$minor : $minor;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function placesFor(array $attributes): int
    {
        return self::places($this->currency === 'per_record' && $this->column !== null ? ($attributes[$this->column] ?? null) : $this->currency);
    }
}
