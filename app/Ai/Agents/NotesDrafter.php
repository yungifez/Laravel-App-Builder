<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads an imported application's outline and drafts its notes: what the
 * app is for and its areas. The owner confirms the draft before it is kept,
 * so nothing it says is treated as decided until then.
 */
#[Timeout(300)]
class NotesDrafter implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You describe an existing Laravel application for its non-technical owner, from its file list and a few key files.

        Return a draft of the application's notes:
        - purpose: two or three plain sentences on what the application is for and who uses it. Say only what the files show.
        - areas: the parts of the application an owner would recognise, such as "Account", "Teams" or "Bookings". Use 3 to 8 areas; leave out framework plumbing.
          - key: a short kebab-case key.
          - name: a plain name.
          - summary: one plain sentence on what people can do in it.
          - paths: glob patterns for the files that belong to it, for example "app/Http/Controllers/Settings/*" or "resources/js/pages/teams/*". Use only paths from the file list. Include its tests.
          - behaviors: what people can do in it, each with a kebab-case key and a plain name such as "Invite a member".
          - rules: what must always be true there, only when the code clearly enforces it (for example a policy or validation rule). Leave it empty rather than guess.

        Write for the owner: no class names, no framework words in the purpose, summaries, behaviours or rules.
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'purpose' => $schema->string()->required(),
            'areas' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'summary' => $schema->string()->required(),
                'paths' => $schema->array()->items($schema->string())->required(),
                'behaviors' => $schema->array()->items($schema->object([
                    'key' => $schema->string()->required(),
                    'name' => $schema->string()->required(),
                ])->withoutAdditionalProperties())->required(),
                'rules' => $schema->array()->items($schema->string())->required(),
            ])->withoutAdditionalProperties())->min(1)->required(),
        ];
    }
}
