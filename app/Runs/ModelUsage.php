<?php

namespace App\Runs;

/**
 * Read how much a model read and wrote from its answer as it streams by,
 * without holding the answer. Anthropic and OpenAI both say it as
 * "input_tokens" and "output_tokens" (OpenAI's older chat answers say
 * "prompt_tokens" and "completion_tokens"), and a stream says it again as
 * the count grows, so the largest number seen is the total. Anthropic
 * counts what it read from its prompt cache apart, so that is added.
 */
class ModelUsage
{
    /**
     * The largest count seen of each field.
     *
     * @var array<string, int>
     */
    protected array $counts = [];

    /**
     * The end of the last piece, so a number cut between two pieces is
     * still read whole.
     */
    protected string $tail = '';

    public function read(string $chunk): void
    {
        $text = $this->tail.$chunk;

        if (preg_match_all('/"(input_tokens|prompt_tokens|cache_creation_input_tokens|cache_read_input_tokens|output_tokens|completion_tokens)"\s*:\s*(\d+)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [, $field, $count]) {
                $this->counts[$field] = max($this->counts[$field] ?? 0, (int) $count);
            }
        }

        $this->tail = substr($text, -64);
    }

    public function inputTokens(): int
    {
        return max($this->counts['input_tokens'] ?? 0, $this->counts['prompt_tokens'] ?? 0)
            + ($this->counts['cache_creation_input_tokens'] ?? 0)
            + ($this->counts['cache_read_input_tokens'] ?? 0);
    }

    public function outputTokens(): int
    {
        return max($this->counts['output_tokens'] ?? 0, $this->counts['completion_tokens'] ?? 0);
    }
}
