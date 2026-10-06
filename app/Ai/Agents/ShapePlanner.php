<?php

namespace App\Ai\Agents;

use App\Ai\Attributes\Tier;
use App\Ai\Middleware\RedactSecrets;
use App\Enums\ModelRole;
use App\Scaffolding\FieldType;
use App\Scaffolding\Scaffold;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Describes the new kinds of record a planned change stores, so the files
 * that hold them are written by code. It is asked apart from the planner:
 * in one format the two were too large for the AI service to accept.
 */
#[Timeout(300)]
#[Tier(ModelRole::Planner)]
class ShapePlanner implements Agent, HasMiddleware, HasStructuredOutput
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
        A change to a Laravel application has been planned. You describe each new kind of record it stores, so the files that hold it are written for the developer.

        - data_shape: give the model name in StudlyCase singular (Booking) and its fields: a snake_case name, a type (string, text, integer, decimal, boolean, date, datetime, email, choice for one of a few fixed values, belongs_to for a link to another record), whether it is required, the choices for a choice field as snake_case values (else empty), and for belongs_to the model it links to in of (else ""), with the field named after the link (customer, not customer_id). Also give each record and field a label in the owner's words: the record as a singular noun ("booking"), each field as the owner would say it ("who booked it", "when it starts", "the price"). Leave out id and timestamps. Say who may view, create, update and delete each record in access: everyone (guests too), signed_in, or creator (only the person who added it, which needs a belongs_to field of User saying who added it); null when the request does not say and the conventions do not settle it. List only records the app does not have yet (its models are listed): changes to existing records stay in the plan's tasks. Empty when nothing new is stored.
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'data_shape' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'label' => $schema->string()->required(),
                'fields' => $schema->array()->items($schema->object([
                    'name' => $schema->string()->required(),
                    'type' => $schema->string()->enum(array_column(FieldType::planned(), 'value'))->required(),
                    'required' => $schema->boolean()->required(),
                    'choices' => $schema->array()->items($schema->string())->required(),
                    'of' => $schema->string()->required(),
                    'label' => $schema->string()->required(),
                ])->withoutAdditionalProperties())->required(),
                'access' => $schema->object(collect(Scaffold::ACTIONS)->mapWithKeys(fn (string $action) => [
                    $action => $schema->string()->enum(Scaffold::WHO)->required(),
                ])->all())->withoutAdditionalProperties()->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
