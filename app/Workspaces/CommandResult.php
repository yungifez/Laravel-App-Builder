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
    ) {}

    /**
     * Determine if the command finished successfully.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
