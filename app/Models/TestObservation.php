<?php

namespace App\Models;

use App\Features\PatchSummary;
use App\Features\TestMap;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * @property array<string, array<int, list<array{int, int}>>>|null $lines Per code file, the line ranges each test (by index) ran; null when recorded before lines were kept
 * @property string|null $error Why nothing was observed, when nothing was
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'feature_request_id', 'verification_id', 'tests', 'files', 'lines', 'error'])]
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
            'lines' => 'array',
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
     * Count the tests the app itself has, for the owner.
     */
    public static function countFor(Project $project): ?int
    {
        $tests = self::appTests($project);

        return $tests === null ? null : count($tests);
    }

    /**
     * Group the app's own tests by the file they are in, each said in
     * plain words, so the owner can see what they check ("Password reset":
     * "Reset password link can be requested", …).
     *
     * @return list<array{name: string, checks: list<string>}>
     */
    public static function checksFor(Project $project): array
    {
        $groups = [];

        foreach (self::appTests($project) ?? [] as $test) {
            $check = TestMap::describe(Str::afterLast($test['id'], '::'));

            // The starter kits' placeholder checks nothing about the app.
            if ($check === 'That true is true') {
                continue;
            }

            $groups[TestMap::describe(Str::beforeLast(basename((string) $test['file']), 'Test.php'))][] = $check;
        }

        // The starter kits' "Example" file names nothing the owner knows,
        // so its checks go last, as the rest.
        if (isset($groups['Example'])) {
            $groups = [...array_diff_key($groups, ['Example' => true]), __('Other checks') => $groups['Example']];
        }

        return array_map(fn (string $name, array $checks) => ['name' => $name, 'checks' => array_values(array_unique($checks))], array_keys($groups), array_values($groups));
    }

    /**
     * Get the tests the app itself has, as last run. Until a change is
     * kept, the latest map is from a change still waiting, and the tests
     * that change added are not in the app: they are left out, so the
     * owner is never told that tests guard what they have not kept.
     *
     * @return list<array{id: string, file: string|null, groups: list<string>}>|null
     */
    protected static function appTests(Project $project): ?array
    {
        $observation = self::latestFor($project);
        $request = $observation?->featureRequest;

        if ($observation === null || $request === null || $request->isAccepted()) {
            return $observation?->tests;
        }

        $added = PatchSummary::addedTests($request->patch);
        $touched = array_column(PatchSummary::files($request->patch), 'path');

        return array_values(array_filter($observation->tests, fn (array $test) => ! in_array($test['file'], $touched, true)
            || ! in_array(TestMap::describe(Str::afterLast($test['id'], '::')), $added, true)));
    }

    /**
     * Get how many of the project's tests ran.
     */
    public function testCount(): int
    {
        return count($this->tests);
    }

    /**
     * Get the observation as a map.
     */
    public function map(): TestMap
    {
        return TestMap::fromArray($this->tests, $this->files, $this->lines ?? []);
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
