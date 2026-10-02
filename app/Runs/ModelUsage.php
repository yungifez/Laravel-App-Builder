<?php

namespace App\Runs;

/**
 * Read how much a model wrote from its answer as it streams by, without
 * holding the answer. Anthropic and OpenAI both say it as "output_tokens"
 * (OpenAI's older chat answers say "completion_tokens"), and a stream says
 * it again as the count grows, so the largest number seen is the total.
 */
class ModelUsage
{
    protected int $outputTokens = 0;

    /**
     * The end of the last piece, so a number cut between two pieces is
     * still read whole.
     */
    protected string $tail = '';

    public function read(string $chunk): void
    {
        $text = $this->tail.$chunk;

        if (preg_match_all('/"(?:output_tokens|completion_tokens)"\s*:\s*(\d+)/', $text, $matches)) {
            $this->outputTokens = max($this->outputTokens, ...array_map(intval(...), $matches[1]));
        }

        $this->tail = substr($text, -64);
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }
}
