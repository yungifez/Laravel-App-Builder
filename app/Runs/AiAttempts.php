<?php

namespace App\Runs;

use App\Models\Run;
use Illuminate\Container\Attributes\Scoped;

/**
 * What the AI services said during one call for a run. The SDK keeps only
 * the last provider's error when it fails over, so the earlier providers'
 * reasons are kept here while the call lasts.
 */
#[Scoped]
class AiAttempts
{
    protected ?int $runId = null;

    /**
     * The call in which an earlier provider said our account is out of
     * credit, if any.
     */
    protected ?string $outOfCreditIn = null;

    /**
     * Ask the AI services for the given run, so what each provider says on
     * the way is recorded on it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function for(Run $run, callable $callback): mixed
    {
        $previous = $this->runId;
        $this->runId = $run->id;

        try {
            return $callback();
        } finally {
            $this->runId = $previous;
        }
    }

    /**
     * Get the run the AI services are being asked for, if any.
     */
    public function run(): ?Run
    {
        return $this->runId === null ? null : Run::query()->find($this->runId);
    }

    /**
     * Start following a call: what an earlier call said no longer counts.
     */
    public function started(string $invocationId): void
    {
        if ($this->outOfCreditIn !== $invocationId) {
            $this->outOfCreditIn = null;
        }
    }

    /**
     * Remember that a provider in this call said our account is out of
     * credit, before the call moved on to the next provider.
     */
    public function outOfCredit(string $invocationId): void
    {
        $this->outOfCreditIn = $invocationId;
    }

    /**
     * Determine if a provider earlier in the latest call said our account
     * is out of credit.
     */
    public function saidOutOfCredit(): bool
    {
        return $this->outOfCreditIn !== null;
    }
}
