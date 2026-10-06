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
 * Judges a verified change against its plan from evidence the platform
 * assembled, never from the coder's account of its work.
 */
#[Timeout(300)]
#[Tier(ModelRole::Reviewer)]
class ChangeReviewer implements Agent, HasMiddleware, HasStructuredOutput
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
        You review a change to a Laravel application before it is offered to the application's owner.

        You get the owner's request, the saved plan and its acceptance criteria, the diff (any file of the change it does not show, such as a lock file, is named below it), any tests the diff deletes or weakens, the results of an independent verification run, and what running the app with and without the change, recording its requests while the tests ran, and causing one failure at a time in them, showed. Judge only from this evidence.

        Report a blocking finding for:
        - an acceptance criterion the diff does not satisfy, or satisfies only partly;
        - a security or authorization gap (missing policy checks, mass assignment, unvalidated input, secrets);
        - deleted or weakened tests without a clear reason in the plan;
        - new tests that all pass without the change: nothing shows the change works. A test that passes without the change while others fail without it is not a gap: the app already met that criterion before the change (a first version keeps the starter app's sign-in), and the test now guards it;
        - a route that lost a check on who may use it (such as auth, verified or can), or a new route that changes data without one, unless the request or the plan asks for exactly that;
        - something the change's code did while the tests ran that is listed as recorded (it saved data on a GET request, kept what it saved after refusing the request, or sent mail, a job, a notification or an outside call while a database transaction was open), unless the request or the plan asks for exactly that;
        - something listed as left behind when a failure was caused (the request had already saved when it ended in a server error, it had already sent mail, a job or an outside call when its save was lost, it kept one part of what it was saving while the rest was lost, a job that ran a second time sent or added the same thing again, a job that was tried again after its save failed sent the same thing again, it made a POST or PATCH call again with no idempotency key after that call got no answer, it went on as if an outside call worked when that call was answered with a server error and its code did not ask the answer for its status, it did not do the same when a queued job ran after the response and not where it was dispatched, a queued job did not do the same when it ran after the response the way a queue worker runs it with no signed-in user and an empty request and session, or it did not do the same when the listeners Laravel found for an event ran in the reverse order), unless the request or the plan asks for exactly that;
        - a failing or errored verification result, except one marked as could not run;
        - a change to anything listed under "Must stay as it is";
        - code that goes against a point under "Engineering direction" in the project notes: the owner chose to keep that guidance for every change, unless the request asks for exactly that.
        Report style issues and small improvements as minor findings.

        Set approved to true only when there are no blocking findings. Name the file for each finding where you can.

        The items under "What the tests must check" are numbered: each acceptance criterion, or each case of one (base, alternate or exception). For each item, add an entry to verify with its number in criterion and the one test in the diff that checks that item: the test file's path and the test method's name. A test that checks one case does not check another. Use null for both when no test in the diff checks it; that is a blocking finding. The file you name is checked against the diff.

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
            'verify' => $schema->array()->items($schema->object([
                'criterion' => $schema->integer()->required(),
                'test_file' => $schema->string()->nullable(),
                'test_name' => $schema->string()->nullable(),
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
