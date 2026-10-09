<?php

namespace App\Models;

use App\Enums\HealthCheckScope;
use App\Enums\HealthCheckStatus;
use Carbon\CarbonImmutable;
use Database\Factories\HealthCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of a project's current version, with no change: the full one
 * the owner asks for from the quick check (the setup, every check and the
 * package lookups), or the package lookups alone, which the scheduler runs
 * now and then.
 *
 * @property int $id
 * @property int $project_id
 * @property string $commit_sha
 * @property HealthCheckScope $scope
 * @property HealthCheckStatus $status
 * @property list<array{name: string, kind: 'setup'|'check'|'packages', passed: bool, output?: string, packages?: list<string>|null}>|null $results What a failed step said is for the builder, never the owner
 * @property string|null $error
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['commit_sha', 'scope', 'status', 'results', 'error', 'finished_at'])]
class HealthCheck extends Model
{
    /** @use HasFactory<HealthCheckFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => HealthCheckScope::class,
            'status' => HealthCheckStatus::class,
            'results' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get what the owner should know, in the quick check's shape: checks
     * that did not pass and packages with known problems. What a step said
     * stays with the builder.
     *
     * @return list<array{title: string, details: list<string>}>
     */
    public function findings(): array
    {
        if ($this->status === HealthCheckStatus::Errored) {
            return [['title' => (string) $this->error, 'details' => []]];
        }

        $setup = null;
        $failed = [];
        $known = [];
        $unread = false;

        foreach ($this->results ?? [] as $result) {
            if ($result['passed']) {
                continue;
            }

            if ($result['kind'] === 'setup') {
                $setup ??= $result['name'];
            } elseif ($result['kind'] === 'check') {
                $failed[] = $result['name'];
            } elseif (isset($result['packages'])) {
                $known = [...$known, ...$result['packages']];
            } else {
                // No packages: the lookup itself failed, so nothing is known.
                $unread = true;
            }
        }

        $findings = [];

        if ($setup !== null) {
            $findings[] = ['title' => __('Your app could not be set up, so its checks did not run.'), 'details' => [$setup]];
        }

        if ($failed !== []) {
            $findings[] = ['title' => __('Some of your app\'s checks do not pass.'), 'details' => $failed];
        }

        if ($known !== []) {
            $findings[] = ['title' => __('Some packages your app uses have known security problems.'), 'details' => array_values(array_unique($known))];
        }

        if ($unread) {
            $findings[] = ['title' => __('This is our fault: I could not look up known problems in your app\'s packages. Check your app again in a while.'), 'details' => []];
        }

        return $findings;
    }

    /**
     * Get what failed, with what each step said, for the builder to fix.
     * A lookup that could not be read has nothing to fix, and a check we
     * could not finish is ours to run again.
     *
     * @return list<array{name: string, output: string}>
     */
    public function failures(): array
    {
        if ($this->status !== HealthCheckStatus::Failed) {
            return [];
        }

        $failures = [];

        foreach ($this->results ?? [] as $result) {
            if ($result['passed'] || ($result['kind'] === 'packages' && ! isset($result['packages']))) {
                continue;
            }

            $failures[] = [
                'name' => $result['name'],
                'output' => $result['kind'] === 'packages'
                    ? 'Known high or critical security problems in: '.implode(', ', $result['packages'] ?? []).'.'
                    : $result['output'] ?? '',
            ];
        }

        return $failures;
    }

    /**
     * Get the packages its lookups found known problems in. Null when no
     * lookup could be read, so nothing is known.
     *
     * @return list<string>|null
     */
    public function problemPackages(): ?array
    {
        $packages = null;

        foreach ($this->results ?? [] as $result) {
            if ($result['kind'] === 'packages' && isset($result['packages'])) {
                $packages = [...$packages ?? [], ...$result['packages']];
            }
        }

        return $packages === null ? null : array_values(array_unique($packages));
    }

    /**
     * Get the project that was checked.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
