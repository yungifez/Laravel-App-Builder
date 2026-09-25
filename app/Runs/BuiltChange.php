<?php

namespace App\Runs;

/**
 * What a construction driver reports about the change it made. The change
 * itself is read back from the workspace, not taken from the driver.
 */
final readonly class BuiltChange
{
    /**
     * @param  list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>  $steps
     * @param  list<string>  $acceptance  Protected acceptance test files that apply to the change
     */
    public function __construct(
        public string $summary,
        public array $steps,
        public array $acceptance = [],
        public ?string $solutionKey = null,
    ) {}
}
