<?php

namespace App\Runs;

/**
 * Add our working rules to an agent's model call as it passes the gateway,
 * so the box holds only the task (architecture §16). Anthropic reads them
 * as one more system block after the agent's own; OpenAI as more of its
 * instructions, or a system message for the older chat calls. A call the
 * rules do not fit, such as listing models, passes as it came.
 */
class GatewayInstructions
{
    /**
     * Get the call's body with our rules added.
     */
    public function add(string $provider, string $path, string $body, string $instructions): string
    {
        // Objects, not arrays, so an empty {} in a tool's schema stays one.
        $call = json_decode($body);

        if (! $call instanceof \stdClass) {
            return $body;
        }

        $path = trim($path, '/');

        if ($provider === 'anthropic' && preg_match('#(^|/)messages(/count_tokens)?$#', $path) === 1) {
            $system = $call->system ?? [];
            $blocks = is_string($system) ? ($system === '' ? [] : [(object) ['type' => 'text', 'text' => $system]]) : (array) $system;
            $call->system = [...$blocks, (object) ['type' => 'text', 'text' => $instructions]];
        } elseif ($provider === 'openai' && preg_match('#(^|/)responses(/compact)?$#', $path) === 1) {
            $own = is_string($call->instructions ?? null) ? $call->instructions : '';
            $call->instructions = ltrim("{$own}\n\n{$instructions}");
        } elseif ($provider === 'openai' && preg_match('#(^|/)chat/completions$#', $path) === 1 && is_array($call->messages ?? null)) {
            $call->messages = [(object) ['role' => 'system', 'content' => $instructions], ...$call->messages];
        } else {
            return $body;
        }

        return (string) json_encode($call, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Take our rules out of what the provider sent back, so an error that
     * quotes the call never shows them to the box. Each long line is taken
     * out on its own too, in case only part was quoted, as written or as
     * JSON writes it.
     */
    public function scrub(string $text, string $instructions): string
    {
        $parts = array_filter(
            [$instructions, ...preg_split('/\R+/', $instructions) ?: []],
            fn (string $part) => mb_strlen(trim($part)) >= 24,
        );
        $quoted = array_map(fn (string $part) => substr((string) json_encode($part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1), $parts);

        // The longest first, so a whole quote goes before its lines.
        $all = array_unique([...$parts, ...$quoted]);
        usort($all, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace($all, '[removed]', $text);
    }
}
