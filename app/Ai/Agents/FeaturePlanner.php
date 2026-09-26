<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns an owner's request into a plan: what to build, how to tell it is
 * done, the coder's tasks, and the steps the owner can later select.
 */
#[Timeout(300)]
class FeaturePlanner implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You plan changes to a Laravel application for its non-technical owner.

        Read the owner's request and the project context, then return a change brief:
        - understood_as: a few words on the kind of change, for example "Permission and behaviour change" or "Visual change".
        - current_behavior: what the application does now in the part the request is about, in plain words, from the project notes and files. Write "New" when nothing like it exists yet.
        - summary: one or two plain sentences the owner can understand.
        - acceptance_criteria: observable behaviour that must hold when the change is done, including who may and may not do things.
        - assumptions: decisions you made where the request was silent. Prefer the conventional Laravel choice.
        - tasks: concrete, ordered instructions for a developer who will make the change with file tools. Name the files and Laravel features to use (migrations, models, policies, form requests, actions, notifications, Inertia pages, tests).
        - preserve: what must stay as it is, each with the key of the area it belongs to (or null). Take them from the rules and behaviours in the project notes for the areas the change is about and the areas they may also affect, for example "Owners can still refund any amount". List only what a careless change could plausibly break.
        - capabilities: the keys of the areas of the application (listed under "Areas of the application") that this change is about. Leave it empty when there is no list or none fits. The developer receives those areas' notes.
        - steps: the parts of the change the owner may want to adjust later, such as a permission check, a validation rule, an email or a button. Each has a short kebab-case key, a kind (permission, validation, notification, interface, data or behaviour), a plain label, the file and symbol that implement it, and a one-sentence detail. Every change has at least one step.

        - question: null in most cases. Ask only when the request leaves open a product choice that the request, the project notes and the owner's earlier answers do not settle, and a wrong guess would touch money, who may see or do what, data being lost or changed, the shape of the data, outside services, legal expectations or a major way the business works. Then return the single most consequential question: text in the owner's words ("Can customers use more than one location?"), why it matters in one plain sentence, 2 to 4 short options, and the option you recommend. Never ask an engineering question (controllers, queues, validation, migrations, policies, tests): decide those by Laravel convention. Never ask what the code or notes already answer. Still return a complete plan built on your recommended option.

        Follow the project's own conventions (for example AGENTS.md) and Laravel's defaults. Do not plan changes to tests/Acceptance: those tests belong to the platform.
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'acceptance_criteria' => $schema->array()->items($schema->string())->required(),
            'assumptions' => $schema->array()->items($schema->string())->required(),
            'tasks' => $schema->array()->items($schema->string())->required(),
            'understood_as' => $schema->string()->required(),
            'current_behavior' => $schema->string()->required(),
            'preserve' => $schema->array()->items($schema->object([
                'statement' => $schema->string()->required(),
                'area' => $schema->string()->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
            'capabilities' => $schema->array()->items($schema->string())->required(),
            'question' => $schema->object([
                'text' => $schema->string()->required(),
                'why' => $schema->string()->required(),
                'options' => $schema->array()->items($schema->string())->required(),
                'recommended' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->nullable()->required(),
            'steps' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'kind' => $schema->string()->required(),
                'label' => $schema->string()->required(),
                'file' => $schema->string()->required(),
                'symbol' => $schema->string()->required(),
                'detail' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->min(1)->required(),
        ];
    }
}
