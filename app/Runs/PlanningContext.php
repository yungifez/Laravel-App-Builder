<?php

namespace App\Runs;

use App\Context\ProjectContext;
use App\Projects\Frontend;

/**
 * The bounded view of the project a planner works from.
 */
final readonly class PlanningContext
{
    /**
     * @param  list<string>  $files  The project's files, possibly truncated
     * @param  array<string, string>  $contents  Selected file contents, keyed by path
     * @param  array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}|null  $targetStep
     * @param  list<array{question: string, answer: string, decided_by: string}>  $answers  What the owner answered for this request
     * @param  bool  $mayAsk  Whether the planner may still ask the owner a question
     * @param  bool  $parentAnswered  Whether the earlier request was a question that was answered, not a change
     * @param  bool  $keepOldWorking  Whether changes must carry the app's old data and links forward
     * @param  list<string>  $services  The outside services the app is connected to
     * @param  list<string>  $routes  The app's addresses and the code that handles each
     * @param  Frontend|null  $frontend  What the app's screens are made with
     * @param  array<string, string>  $areas  The areas the change is about by evidence, with why (SelectAreas)
     * @param  list<string>  $names  The page data and relations the code of those areas already uses (AreaNames)
     */
    public function __construct(
        public string $request,
        public array $files,
        public array $contents,
        public ?string $parentRequest = null,
        public ?string $parentSummary = null,
        public ?array $targetStep = null,
        public ProjectContext $projectContext = new ProjectContext,
        public array $answers = [],
        public bool $mayAsk = false,
        public bool $parentAnswered = false,
        public bool $keepOldWorking = true,
        public array $services = [],
        public array $routes = [],
        public ?Frontend $frontend = null,
        public array $areas = [],
        public array $names = [],
    ) {}
}
