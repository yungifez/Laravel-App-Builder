<?php

namespace App\Models;

use App\Enums\VerificationStatus;
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
 * @property int $feature_request_id
 * @property int|null $run_id
 * @property int|null $workspace_id
 * @property VerificationStatus $status
 * @property list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string, tests?: list<array{file: string, name: string, outcome: string}>}>|null $results
 * @property array{pages: list<array<string, mixed>>, signed_in?: bool, shots?: list<array{screen: string, width: int, path: string}>}|null $screens
 * @property list<array{rule: string, path: string, line: int}>|null $shortcuts What the shortcut scan found in the files the change touched, or null when it did not run
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['run_id', 'workspace_id', 'status', 'results', 'screens', 'shortcuts', 'error', 'started_at', 'finished_at'])]
class Verification extends Model
{
    /** @use HasFactory<VerificationFactory> */
    use HasFactory;

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
