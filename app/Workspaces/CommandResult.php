<?php

namespace App\Workspaces;

class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public int $durationMs,
        public bool $timedOut = false,
        // No runner took the command, or it never answered, so it did not
        // run to an end: the result says nothing about the code.
        public bool $lost = false,
    ) {}

    /**
     * Determine if the command finished successfully.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
