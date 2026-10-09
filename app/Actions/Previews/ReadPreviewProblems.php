<?php

namespace App\Actions\Previews;

use App\Actions\Features\RetryFeatureRequest;
use App\Enums\FeatureRequestStatus;
use App\Models\ClearedProblem;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Previews\LoggedProblems;
use Carbon\CarbonImmutable;

class ReadPreviewProblems
{
    public function __construct(private ReadPreviewLog $readLog) {}

    /**
     * Get the problems the app on show ran into while the owner tried it,
     * the most recent first, each with where it stands: new, being fixed,
     * fixed, cleared by the owner, fine to fail while something is down, or
     * back after a fix or a clearance, and the owner's
     * last try to fix it when that try stopped.
     *
     * @return list<array{id: string, words: string, class: string|null, message: string, place: string|null, trace: list<string>, during: string|null, count: int, first_at: string|null, last_at: string|null, state: string, change: string|null, stopped: string|null}>
     */
    public function handle(Project $project): array
    {
        $preview = $this->readLog->preview($project);
        $problems = $preview === null ? [] : LoggedProblems::in($this->readLog->handle($preview));

        if ($problems === []) {
            return [];
        }

        $ids = array_column($problems, 'id');
        $asked = $project->featureRequests()
            ->whereIn('live_errors->problem', $ids)
            ->whereNull('dismissed_at')
            ->latest('id')
            ->get();
        // A fix that stopped is not being fixed: the problem waits again.
        $stopped = fn (FeatureRequest $fix) => in_array($fix->status, [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled], true)
            || RetryFeatureRequest::retryable($fix);
        $byProblem = fn (FeatureRequest $fix) => (string) ($fix->live_errors['problem'] ?? '');
        $fixes = $asked->reject($stopped)->unique($byProblem)->keyBy($byProblem);
        // The newest try, when it stopped, so the owner can see why.
        $tries = $asked->unique($byProblem)->filter($stopped)->keyBy($byProblem);
        $cleared = $project->clearedProblems()->whereIn('problem', $ids)->get()->keyBy('problem');

        return array_map(function (array $problem) use ($fixes, $tries, $cleared) {
            $stand = $this->stand($problem['last_at'], $fixes->get($problem['id']), $cleared->get($problem['id']));
            $try = in_array($stand['state'], ['new', 'back'], true) ? $tries->get($problem['id']) : null;

            return [...$problem, ...$stand, 'stopped' => $try?->uuid];
        }, $problems);
    }

    /**
     * Say where a problem stands. A kept fix or a clearance holds until the
     * app runs into the problem again. Failing that the owner said is fine
     * holds for good, since it will happen again whenever the same thing is
     * down.
     *
     * @return array{state: string, change: string|null}
     */
    protected function stand(?string $lastAt, ?FeatureRequest $fix, ?ClearedProblem $clearance): array
    {
        $last = $lastAt === null ? null : CarbonImmutable::parse($lastAt);
        $since = fn (CarbonImmutable $at) => $last !== null && $last->greaterThan($at);

        if ($fix !== null && $fix->accepted_at !== null && $fix->reverted_at === null) {
            return ['state' => $since(CarbonImmutable::instance($fix->accepted_at)) ? 'back' : 'fixed', 'change' => $fix->uuid];
        }

        if ($fix !== null && $fix->accepted_at === null) {
            return ['state' => 'fixing', 'change' => $fix->uuid];
        }

        if ($clearance?->fine === true) {
            return ['state' => 'fine', 'change' => null];
        }

        if ($clearance !== null) {
            return ['state' => $since($clearance->cleared_at) ? 'back' : 'cleared', 'change' => null];
        }

        return ['state' => 'new', 'change' => null];
    }
}
