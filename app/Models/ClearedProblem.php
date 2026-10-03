<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClearedProblemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A problem the owner cleared from the list of problems their app ran into.
 * It shows again when the app runs into it after it was cleared, unless the
 * owner said failing is fine while something was down on purpose.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property string $problem
 * @property bool $fine
 * @property CarbonImmutable $cleared_at
 */
#[Fillable(['user_id', 'problem', 'fine', 'cleared_at'])]
class ClearedProblem extends Model
{
    /** @use HasFactory<ClearedProblemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fine' => 'boolean',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * Get the project the problem was cleared in.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
