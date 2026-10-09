<?php

namespace App\Actions\Features;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Projects\ProjectRepository;

class DescribeAskedFor
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Gather, per part of the app, what the owner asked for in the changes
     * they kept, in their own words: each acceptance criterion a named test
     * proved when the change was kept. With the app's current code, each
     * says whether that test is still there, so a promise quietly lost
     * shows. The app's code is read rather than its last test map, since
     * that map can predate a change the owner kept since.
     *
     * A change counts for the first part it was about.
     *
     * @return array<string, list<array{text: string, checked: bool|null}>>
     */
    public function handle(Project $project, ?string $revision): array
    {
        $items = [];

        $kept = $project->featureRequests()
            ->whereNotNull('accepted_at')
            ->whereNull('reverted_at')
            ->oldest('accepted_at')
            ->with('latestRun')
            ->get();

        foreach ($kept as $featureRequest) {
            /** @var FeatureRequest $featureRequest */
            $review = $featureRequest->latestRun?->review;
            $part = $review['classification']['targets'][0] ?? null;

            if ($part === null) {
                continue;
            }

            foreach ($review['verified'] as $item) {
                if (in_array($item['evidence'], ['tested', 'already_true'], true) && ($item['test_name'] ?? null) !== null) {
                    $items[$part][$item['criterion']] = $item['test_name'];
                }
            }
        }

        $present = $revision === null ? null : array_flip($this->repository->testNames(
            $project, $revision, array_values(array_unique(array_merge([], ...array_map(array_values(...), array_values($items))))),
        ));

        return array_map(fn (array $criteria) => array_map(fn (string $text, string $test) => [
            'text' => $text,
            'checked' => $present === null ? null : isset($present[$test]),
        ], array_keys($criteria), array_values($criteria)), $items);
    }
}
