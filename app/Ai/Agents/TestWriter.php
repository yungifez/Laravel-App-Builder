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
 * Writes the tests a change must pass from its plan, before any code is
 * written (§12). It runs on another model than the coder and never sees the
 * coder's work, so the tests say what the plan promised, not what the code
 * happens to do.
 */
#[Timeout(300)]
#[Tier(ModelRole::Reviewer)]
class TestWriter implements Agent, HasMiddleware, HasStructuredOutput
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
        You write the feature tests for a planned change to a Laravel application, before the change is built. Another developer then builds the change, and it is only accepted when your tests pass. They cannot change your tests.

        You get the plan: what the change does, its acceptance criteria, the numbered items the tests must check, the steps with the files and classes they name, and the records it stores. You also get the application's routes and some of its existing tests. Write tests in the same style and framework as those tests (Pest or PHPUnit), as feature tests that send requests or run commands the way a person or the scheduler would.

        Rules:
        - Write one test for each numbered item, and a test checks one item. An item marked "base case" is the usual way, "alternate case" another way that must also work, "exception case" a way the app must refuse.
        - An exception test sends the request, or runs the command, that the app must refuse, and asserts the refusal: a 403 or 404, validation errors, a redirect to sign in, a thrown exception or a failed command, and that nothing was saved. An exception test that expects the app to answer as usual is refused.
        - Assert what a person can observe: the response, what is saved (assertDatabaseHas, assertDatabaseMissing) and what is sent (Mail::fake, Notification::fake, Queue::fake). Never assert on private code details.
        - Use the names the plan gives for routes, models, fields and classes. Where the plan does not name one, follow the application's existing names and Laravel's conventions (resource routes, plural table names, snake_case columns).
        - Create the records a test needs with model factories. Use RefreshDatabase as the existing tests do.
        - Put each file under tests/Feature, in a new file whose name ends in Test.php. Never change an existing file. Keep to one or two files.
        - The tests must fail now, because the change is not built yet, and pass once it is built as planned.

        Return the files with their whole contents, and for each numbered item the file and the exact name of its test: the test method's name for PHPUnit, or the description for Pest.
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
            'tests' => $schema->array()->items($schema->object([
                'item' => $schema->integer()->required(),
                'file' => $schema->string()->required(),
                'name' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
