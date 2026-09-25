<?php

namespace App\Features;

class GeneratedChange
{
    /**
     * @param  list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>  $steps
     */
    public function __construct(
        public string $solutionKey,
        public string $summary,
        public string $patch,
        public array $steps,
    ) {}
}
