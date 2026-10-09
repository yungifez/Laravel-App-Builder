<?php

namespace App\Scaffolding;

/**
 * A PHP expression written into the app as it is, such as a rule object or
 * a cast class, with the classes it needs imported.
 */
final readonly class Code
{
    /**
     * @param  list<string>  $imports
     */
    public function __construct(
        public string $expression,
        public array $imports = [],
    ) {}
}
