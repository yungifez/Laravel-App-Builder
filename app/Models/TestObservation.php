<?php

namespace App\Models;

use App\Features\TestMap;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one run of the project's suite with code coverage showed: which
 * tests ran which code files (see TestMap). Kept per verification, so the
 * evidence about the app grows with each change (direction 22, §6).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $feature_request_id
 * @property int|null $verification_id
 * @property list<array{id: string, file: string|null, groups: list<string>}> $tests
 * @property array<string, list<int>> $files
 * @property string|null $error Why nothing was observed, when nothing was
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'feature_request_id', 'verification_id', 'tests', 'files', 'error'])]
class TestObservation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tests' => 'array',
            'files' => 'array',
        ];
    }

    /**
     * Get the latest usable map of the project's tests: from a change the
     * owner kept when there is one, since that code is the app; otherwise
     * from the latest change checked.
     */
    public static function latestFor(Project $project): ?self
    {
        $usable = fn (Builder $query) => $query->where('project_id', $project->id)->whereNull('error')->latest('id');

        return self::query()->tap($usable)
            ->whereHas('featureRequest', fn (Builder $query) => $query->whereNotNull('accepted_at')->whereNull('reverted_at'))
            ->first()
            ?? self::query()->tap($usable)->first();
    }

    /**
     * Get the observation as a map.
     */
    public function map(): TestMap
    {
        return TestMap::fromArray($this->tests, $this->files);
    }

    /**
     * Get the project whose tests were observed.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the change whose checks ran the suite.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the verification that ran the suite.
     *
     * @return BelongsTo<Verification, $this>
     */
    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }
}
