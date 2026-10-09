<?php

namespace Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * An agent that does not say which model tier it runs on.
 */
class UntieredWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Write a farewell.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['farewell' => $schema->string()->required()];
    }
}
