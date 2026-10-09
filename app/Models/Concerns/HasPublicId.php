<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Names a model in links and requests by a random UUID, not its number.
 * A guessed number then finds nothing, even where a check is missed.
 * The number stays the key inside the database.
 */
trait HasPublicId
{
    use HasUuids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
