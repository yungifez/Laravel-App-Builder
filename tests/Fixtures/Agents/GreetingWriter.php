<?php

namespace Tests\Fixtures\Agents;

use App\Ai\Attributes\Tier;
use App\Enums\ModelRole;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * An agent no list names, as a new one would be.
 */
#[Tier(ModelRole::Reviewer)]
class GreetingWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Write a greeting.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['greeting' => $schema->string()->required()];
    }
}
