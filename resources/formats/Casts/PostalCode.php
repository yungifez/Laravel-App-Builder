<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Store a postal code one way however it was typed: uppercase, with one
 * inner space where a code of letters and digits has one ("v6b1a1"
 * becomes "V6B 1A1").
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class PostalCode implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $value)));

        // A code of letters and digits with its space left out, as in
        // Canada and Britain: the last three characters stand apart.
        if (! str_contains($code, ' ') && preg_match('/[A-Z]/', $code) === 1 && preg_match('/^[A-Z0-9]{5,7}$/', $code) === 1) {
            $code = substr($code, 0, -3).' '.substr($code, -3);
        }

        return $code;
    }
}
