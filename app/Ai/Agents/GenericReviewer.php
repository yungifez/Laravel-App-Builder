<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A general-purpose code reviewer, used by the evaluation as the baseline
 * for the pipeline's reviewer (ChangeReviewer). It applies the same blocking
 * criteria to the same evidence, but gets the project's requirements as
 * written rather than the plan, preservation clauses and areas the pipeline
 * derives from them.
 */
#[Timeout(300)]
class GenericReviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You review a change to a Laravel application before it is merged.

        You get the request that asked for the change, the project's requirements as written in its notes, the results of the project's checks, and the full diff. Judge only from this evidence.

        Report a blocking finding for:
        - part of the request the diff does not do, or does only partly;
        - a security or authorization gap (missing policy checks, mass assignment, unvalidated input, secrets);
        - deleted or weakened tests without a clear reason in the request;
        - a failing or errored check;
        - a change that breaks a rule or behaviour in the project's requirements, unless the request asks for that change.
        Report style issues and small improvements as minor findings.

        Set approved to true only when there are no blocking findings. Name the file for each finding where you can.
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
        ];
    }
}
