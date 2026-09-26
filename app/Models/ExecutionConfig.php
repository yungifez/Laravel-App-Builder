<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The settings a run was built with, such as models, drivers and budgets,
 * stored once per distinct set and named by its hash. Runs point at it, so
 * results can be compared across configuration changes. It never holds
 * credentials: only the settings in App\Operations\ExecutionSettings.
 *
 * @property string $version
 * @property array<string, mixed> $settings
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['version', 'settings'])]
class ExecutionConfig extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'version';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
