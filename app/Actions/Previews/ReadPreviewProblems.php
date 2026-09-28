<?php

namespace App\Actions\Previews;

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
     * fixed, cleared by the owner, or back after either.
     *
     * @return list<array{id: string, words: string, class: string|null, message: string, place: string|null, trace: list<string>, count: int, first_at: string|null, last_at: string|null, state: string, change: string|null}>
     */
    public function handle(Project $project): array
    {
        $preview = $this->readLog->preview($project);
        $problems = $preview === null ? [] : LoggedProblems::in($this->readLog->handle($preview));

        if ($problems === []) {
            return [];
        }

        $ids = array_column($problems, 'id');
        $fixes = $project->featureRequests()
            ->whereIn('live_errors->problem', $ids)
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled])
            ->latest('id')
            ->get()
            ->unique(fn (FeatureRequest $fix) => $fix->live_errors['problem'] ?? null)
            ->keyBy(fn (FeatureRequest $fix) => (string) ($fix->live_errors['problem'] ?? ''));
        $cleared = $project->clearedProblems()->whereIn('problem', $ids)->get()->keyBy('problem');

        return array_map(function (array $problem) use ($fixes, $cleared) {
            $fix = $fixes->get($problem['id']);
            $clearance = $cleared->get($problem['id']);

            return [...$problem, ...$this->stand($problem['last_at'], $fix, $clearance)];
        }, $problems);
    }

    /**
     * Say where a problem stands. A kept fix or a clearance holds until the
     * app runs into the problem again.
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

        if ($clearance !== null) {
            return ['state' => $since($clearance->cleared_at) ? 'back' : 'cleared', 'change' => null];
        }

        return ['state' => 'new', 'change' => null];
    }
}
