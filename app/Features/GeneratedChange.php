<?php

namespace App\Features;

class GeneratedChange
{
    /**
     * @param  list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>  $steps
     * @param  list<string>  $acceptance  Protected acceptance test files that apply to the change
     */
    public function __construct(
        public string $solutionKey,
        public string $summary,
        public string $patch,
        public array $steps,
        public array $acceptance = [],
    ) {}
}
