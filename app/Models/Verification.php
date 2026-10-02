<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\VerificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A run of the project's checks against a feature request's change, applied
 * on top of every change it follows up on.
 *
 * @property int $id
 * @property string $uuid Names the row in links and requests
 * @property int $feature_request_id
 * @property int|null $run_id
 * @property int|null $workspace_id
 * @property VerificationStatus $status
 * @property list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string, tests?: list<array{file: string, name: string, outcome: string}>, at_start?: string, new_problems?: list<string>}>|null $results
 * @property array{pages: list<array<string, mixed>>, signed_in?: bool, shots?: list<array{screen: string, width: int, path: string}>}|null $screens
 * @property list<array{rule: string, path: string, line: int}>|null $shortcuts What the shortcut scan found in the files the change touched, or null when it did not run
 * @property array{new_tests?: list<array{file: string, name: string, without_change: string}>, routes?: array{added?: list<array{route: string, middleware: list<string>}>, removed?: list<string>, changed?: list<array{route: string, lost: list<string>, gained: list<string>}>}, new_code?: array{lines: int, run: int, own_tests_only: int, unrun: array<string, list<int>>}, traces?: array{requests: int, reached: int, unseen: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, test: string|null}>, repeats: list<array{path: string, line: int, count: int, route: string}>}, boundaries?: array{phased: int, unknown: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>, read?: list<array{kind: string, what: string, at: string, in: string}>}, conventions?: array{conventions: array<string, array{role: string, places: int, of: int}>, findings: list<array{work: string, role: string, route: string, at: string, in: string, test: string|null}>}, drift?: array{areas: array<string, array{requests: int, effects: int, per: float|int}>, findings: list<array{area: string, per: float|int, ceiling: float|int, far: bool, name: string}>}, containment?: array{services: int, findings: list<array{route: string, what: string, at: string, in: string|null, from: list<string>, home: list<string>, test: string|null}>}, faults?: array{points: int, run: int, missed: int, existing: int, findings: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string}>}}|null $evidence What running the app showed about the change itself, by kind: with and without the change, request by request while its tests ran, and with one failure caused at a time; a kind that was not measured is absent
 * @property string|null $error
 * @property bool $interrupted The checks stopped because of a problem on our side, so they say nothing about the change
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['run_id', 'workspace_id', 'status', 'results', 'screens', 'shortcuts', 'evidence', 'error', 'interrupted', 'started_at', 'finished_at'])]
class Verification extends Model
{
    /** @use HasFactory<VerificationFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'results' => 'array',
            'screens' => 'array',
            'shortcuts' => 'array',
            'evidence' => 'array',
            'interrupted' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the feature request being verified.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the construction run that asked for the verification, if any.
     *
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * Pick the picture that best shows the app: the front page when it was
     * pictured, otherwise the first screen, at its widest.
     */
    public function cover(): ?int
    {
        $shots = collect($this->screens['shots'] ?? [])->map(fn (array $shot, int $index) => [...$shot, 'index' => $index]);
        $front = collect($this->screens['pages'] ?? [])->firstWhere('path', '/')['screen'] ?? null;
        $screen = $shots->contains('screen', $front) ? $front : $shots->first()['screen'] ?? null;

        return $shots->where('screen', $screen)->sortByDesc('width')->first()['index'] ?? null;
    }
}
