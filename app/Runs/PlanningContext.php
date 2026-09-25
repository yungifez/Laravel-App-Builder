<?php

namespace App\Runs;

/**
 * The bounded view of the project a planner works from.
 */
final readonly class PlanningContext
{
    /**
     * @param  list<string>  $files  The project's files, possibly truncated
     * @param  array<string, string>  $contents  Selected file contents, keyed by path
     * @param  array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}|null  $targetStep
     */
    public function __construct(
        public string $request,
        public array $files,
        public array $contents,
        public ?string $parentRequest = null,
        public ?string $parentSummary = null,
        public ?array $targetStep = null,
    ) {}
}
