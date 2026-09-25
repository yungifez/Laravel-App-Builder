<?php

namespace App\Runs;

use App\Enums\OperationStatus;
use App\Models\RunOperation;

/**
 * The outcome of a tool call as the caller sees it.
 */
final readonly class OperationResult
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public OperationStatus $status,
        public array $result,
        public ?string $error,
        public int $revision,
        public bool $replayed = false,
    ) {}

    /**
     * Build the result from a journal entry.
     */
    public static function fromOperation(RunOperation $operation, int $revision, bool $replayed = false): self
    {
        return new self($operation->status, $operation->result ?? [], $operation->error, $revision, $replayed);
    }

    /**
     * Determine if the tool ran successfully.
     */
    public function succeeded(): bool
    {
        return $this->status === OperationStatus::Succeeded;
    }
}
