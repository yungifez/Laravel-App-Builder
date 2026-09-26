<?php

namespace App\Runs\Agents;

use App\Enums\AgentOutcomeStatus;
use Illuminate\Support\Str;

/**
 * How one coding agent attempt ended, with what it cost.
 */
final readonly class AgentOutcome
{
    public function __construct(
        public string $adapter,
        public string $provider,
        public ?string $model,
        public AgentOutcomeStatus $status,
        public ?string $summary = null,
        public ?string $errorKind = null,
        public ?string $error = null,
        public int $turns = 0,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $costUsd = null,
    ) {}

    /**
     * Read the runner's result line: the last line of its output that is a
     * JSON object of type "result". A run that timed out or printed no result
     * failed.
     */
    public static function fromRunnerOutput(string $adapter, string $provider, ?string $model, string $output, bool $timedOut): self
    {
        $result = null;

        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && ($decoded['type'] ?? null) === 'result') {
                $result = $decoded;

                break;
            }
        }

        if ($timedOut || $result === null) {
            return new self($adapter, $provider, $model, AgentOutcomeStatus::Failed,
                errorKind: $timedOut ? 'timeout' : 'no_result',
                error: $timedOut ? __('The agent did not finish in time.') : __('The agent ended without a result.'),
            );
        }

        return new self(
            adapter: $adapter,
            provider: $provider,
            model: $model,
            status: AgentOutcomeStatus::tryFrom((string) ($result['status'] ?? '')) ?? AgentOutcomeStatus::Failed,
            summary: isset($result['summary']) ? Str::limit((string) $result['summary'], 4000) : null,
            errorKind: isset($result['error_kind']) ? (string) $result['error_kind'] : null,
            error: isset($result['error']) ? Str::limit((string) $result['error'], 2000) : null,
            turns: (int) ($result['turns'] ?? 0),
            inputTokens: (int) ($result['input_tokens'] ?? 0),
            outputTokens: (int) ($result['output_tokens'] ?? 0),
            costUsd: isset($result['cost_usd']) ? (float) $result['cost_usd'] : null,
        );
    }

    /**
     * Get the attempt's details for the run log.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status->value,
            'error_kind' => $this->errorKind,
            'error' => $this->error,
            'turns' => $this->turns,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost_usd' => $this->costUsd,
        ];
    }
}
