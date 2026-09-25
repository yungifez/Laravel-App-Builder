<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use Database\Factories\FeatureRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An owner's request for a feature, or for a change to one step of a feature
 * generated earlier (a follow-up with a parent and a target step).
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $prompt
 * @property string|null $target_step
 * @property FeatureRequestStatus $status
 * @property string $generator
 * @property string|null $solution_key
 * @property string|null $summary
 * @property string|null $patch
 * @property list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>|null $steps
 * @property list<string>|null $acceptance Protected acceptance test files that apply to the change
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'user_id', 'parent_id', 'prompt', 'target_step', 'status', 'generator', 'solution_key', 'summary', 'patch', 'steps', 'acceptance', 'error'])]
class FeatureRequest extends Model
{
    /** @use HasFactory<FeatureRequestFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FeatureRequestStatus::class,
            'steps' => 'array',
            'acceptance' => 'array',
        ];
    }

    /**
     * Get the project the request is for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the request this one follows up on.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the follow-up requests made on this one.
     *
     * @return HasMany<FeatureRequest, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get the verification runs for the request's change.
     *
     * @return HasMany<Verification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /**
     * Get this request and the requests it follows up on, oldest first, so
     * their patches can be applied in order.
     *
     * @return list<FeatureRequest>
     */
    public function lineage(): array
    {
        $lineage = [$this];
        $current = $this;

        while ($current->parent_id !== null) {
            $current = $current->parent()->firstOrFail();
            array_unshift($lineage, $current);
        }

        return $lineage;
    }

    /**
     * Find one of the generated change's steps by key.
     *
     * @return array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}|null
     */
    public function step(string $key): ?array
    {
        return collect($this->steps ?? [])->firstWhere('key', $key);
    }
}
