<?php

namespace App\Runs;

use App\Context\ProjectContext;

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
        public ProjectContext $projectContext = new ProjectContext,
    ) {}

    /**
     * Get the areas the request is known to be about before planning: the
     * areas that claim the file of the step the owner selected.
     *
     * @return list<string>
     */
    public function preselectedCapabilities(): array
    {
        return $this->targetStep === null ? [] : $this->projectContext->claiming($this->targetStep['file']);
    }
}
