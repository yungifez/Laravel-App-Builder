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
 * Reads an application's outline and the facts its exploration gathered,
 * and drafts its notes: what the app is for and its areas. The owner
 * confirms the draft part by part before it is kept, so nothing it says is
 * treated as decided until then.
 */
#[Timeout(300)]
#[Tier(ModelRole::Planner)]
class NotesDrafter implements Agent, HasMiddleware, HasStructuredOutput
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
        You describe an existing Laravel application for its non-technical owner, from its file list, a few key files, and facts gathered by running it: what its code holds, its routes with their middleware, what each of its test files checks and which code it ran, and the pages its tests opened.
        Prefer the facts to guessing: code that tests run together belongs together, and a part the tests never touch is less certain.

        Return a draft of the application's notes:
        - purpose: two or three plain sentences on what the application is for and who uses it. Say only what the files show.
        - areas: the parts of the application an owner would recognise, such as "Account", "Teams" or "Bookings". Use 3 to 8 areas; leave out framework plumbing.
          - key: a short kebab-case key.
          - name: a plain name.
          - summary: one plain sentence on what people can do in it.
          - paths: glob patterns for the files that belong to it, for example "app/Http/Controllers/Settings/*" or the folder of its screens, such as "resources/js/pages/teams/*" or "resources/views/livewire/teams/*". Use only paths from the file list. Include its tests.
          - behaviors: what people can do in it, each with a kebab-case key and a plain name such as "Invite a member".
          - rules: what must always be true there, only when the code clearly enforces it (for example a policy, middleware or validation rule). Each rule has its words and its source: the file from the file list that enforces it. A rule without a source is dropped, so leave it out rather than guess.

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
                'rules' => $schema->array()->items($schema->object([
                    'rule' => $schema->string()->required(),
                    'source' => $schema->string()->required(),
                ])->withoutAdditionalProperties())->required(),
            ])->withoutAdditionalProperties())->min(1)->required(),
        ];
    }
}
