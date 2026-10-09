<?php

namespace App\Ai\Agents;

use App\Ai\Attributes\Tier;
use App\Ai\Middleware\RedactSecrets;
use App\Enums\ModelRole;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Brings an application's notes up to date after a change that someone
 * else wrote. Our own coding agent updates the notes as it works; the
 * owner's tool never sees them, so this reads its change and summary and
 * rewrites the notes of the areas the change touched.
 */
#[Timeout(300)]
#[Tier(ModelRole::Reviewer)]
class NotesKeeper implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    /**
     * Keep live keys out of what goes to the model.
     *
     * @return list<RedactSecrets>
     */
    public function middleware(): array
    {
        return [new RedactSecrets];
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You keep the notes of a Laravel application up to date. Each area of the application has one notes file: YAML frontmatter (key, name, summary, paths, behaviors, effects) and then Markdown notes on how the area works and what must stay true in it.

        You are given a change that was just made to the application, the summary its author wrote, and the current notes file of each area the change touched.
        Return the files whose notes the change made wrong or incomplete, each with its path exactly as given and its whole new contents:
        - Keep the frontmatter's shape and keys. Change "summary", "paths" or "behaviors" only where the change clearly did. Never touch "effects".
        - Add or correct only what the change itself shows. Say nothing the code does not show.
        - Keep every note the change did not make wrong, in the same words.
        - Write for the owner: plain words, no class names in the summary or behaviours.

        Leave out a file the change does not affect. Return an empty list when no notes need to change.
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'files' => $schema->array()->items($schema->object([
                'path' => $schema->string()->required(),
                'contents' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
