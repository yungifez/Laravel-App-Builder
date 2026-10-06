<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Store an ISBN as its digits and X only, however it was typed
 * ("978-0-306-40615-7" becomes "9780306406157").
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class Isbn implements CastsAttributes
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
        return $value === null ? null : strtoupper((string) preg_replace('/[^0-9Xx]/', '', (string) $value));
    }
}
