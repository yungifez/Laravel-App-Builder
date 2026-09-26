<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Judges a verified change against its plan from evidence the platform
 * assembled, never from the coder's account of its work.
 */
#[Timeout(300)]
class ChangeReviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You review a change to a Laravel application before it is offered to the application's owner.

        You get the owner's request, the saved plan and its acceptance criteria, the full diff, any tests the diff deletes or weakens, and the results of an independent verification run. Judge only from this evidence.

        Report a blocking finding for:
        - an acceptance criterion the diff does not satisfy, or satisfies only partly;
        - a security or authorization gap (missing policy checks, mass assignment, unvalidated input, secrets);
        - deleted or weakened tests without a clear reason in the plan;
        - a failing or errored verification result;
        - a change to anything listed under "Must stay as it is".
        Report style issues and small improvements as minor findings.

        Set approved to true only when there are no blocking findings. Name the file for each finding where you can.

        Then describe the change for the owner, who is not technical, as changes: one entry per behaviour they would notice, with what it did before and what it does now, in plain words and without file or class names. Set area to the key of the area it belongs to from "Areas this change touched", or null when none fits. Include behaviour that changed in areas the request was not about: the owner decides whether it is wanted.
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'approved' => $schema->boolean()->required(),
            'summary' => $schema->string()->required(),
            'findings' => $schema->array()->items($schema->object([
                'severity' => $schema->string()->enum(['blocking', 'minor'])->required(),
                'summary' => $schema->string()->required(),
                'file' => $schema->string()->nullable(),
            ])->withoutAdditionalProperties())->required(),
            'changes' => $schema->array()->items($schema->object([
                'area' => $schema->string()->nullable(),
                'behavior' => $schema->string()->required(),
                'before' => $schema->string()->required(),
                'now' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
