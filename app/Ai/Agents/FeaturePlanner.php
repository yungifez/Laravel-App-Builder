<?php

namespace App\Ai\Agents;

use App\Enums\Consequence;
use App\Scaffolding\FieldType;
use App\Scaffolding\Scaffold;
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
        - commit_subject: the git commit subject the application's own developer would write for this change: imperative, under 60 characters, about the code, for example "Add a phone number to the contact form". Do not quote the request.
        - acceptance_criteria: observable behaviour that must hold when the change is done, including who may and may not do things. The owner reads them, so write what a person sees or can do, without code words: "A team with no description shows only its name", not "A team with a null description".
        - assumptions: decisions you made where the request was silent. Prefer the conventional Laravel choice.
        - tasks: concrete, ordered instructions for a developer who will make the change with file tools. Name the files and Laravel features to use (migrations, models, policies, form requests, actions, notifications, Inertia pages, tests).
        - preserve: what must stay as it is, each with the key of the area it belongs to (or null). Take them from the rules and behaviours in the project notes for the areas the change is about and the areas they may also affect, for example "Owners can still refund any amount". List only what a careless change could plausibly break.
        - capabilities: the keys of the areas of the application (listed under "Areas of the application") that this change is about. Leave it empty when there is no list or none fits. The developer receives those areas' notes.
        - next: up to three short things the owner might ask for next, once this is done, in their words, for example "Remind people who have not answered their invitation". Each builds on this change and is under 80 characters. Leave it empty when nothing natural follows. When the project notes have a "Goal" section, prefer ideas that serve that goal.
        - goal: when the project notes have a "Goal" section, one short sentence on how this change serves that goal, in the owner's words, for example "Customers can book without calling, so the front desk takes fewer calls." Null when the notes name no goal, when the change does not bear on it, or when you only answer a question. Never stretch a change to fit the goal.
        - data_shape: each new kind of record the change stores, so the files that hold it are written for you. Give the model name in StudlyCase singular (Booking) and its fields: a snake_case name, a type (string, text, integer, decimal, boolean, date, datetime, email, choice for one of a few fixed values, belongs_to for a link to another record), whether it is required, the choices for a choice field as snake_case values (else empty), and for belongs_to the model it links to in of (else null), with the field named after the link (customer, not customer_id). Also give each record and field a label in the owner's words: the record as a singular noun ("booking"), each field as the owner would say it ("who booked it", "when it starts", "the price"). Leave out id and timestamps. Say who may view, create, update and delete each record in access: everyone (guests too), signed_in, or creator (only the person who added it, which needs a belongs_to field of User saying who added it); null when the request does not say and the conventions do not settle it. List only records the app does not have yet: changes to existing records stay in tasks. Empty when nothing new is stored.
        - steps: the parts of the change the owner may want to adjust later, such as a permission check, a validation rule, an email or a button. Each has a short kebab-case key, a kind (permission, validation, notification, interface, data or behaviour), a plain label, the file and symbol that implement it, and a one-sentence detail. The owner reads the label and detail, so write them in the owner's words, without code, file, field or class names: "Visitors can send a message without signing in", not "Adds a public route to ContactController". Every change has at least one step.

        - answer: null when the owner wants something built or changed. When they only ask about the app as it is ("Who can see invoices?", "What happens when a payment fails?", "How does sign-up work?") and want nothing changed, answer them here instead: a few plain sentences from the project notes and files, in the owner's words, without code or file names. Then summary repeats the answer in one sentence, understood_as is "Question", commit_subject is "", and acceptance_criteria, tasks, steps and preserve are empty. A question is answered even when the honest answer is that the app cannot do it yet ("How do I add classes?" when nothing adds them): say so plainly and what is missing, and put that missing piece first in next, in their words ("Let trainers add classes"), so they ask for it with one tap. Never build what they only asked about. Asking how while saying a screen is empty or something is missing ("How do I get classes? It is empty") is still a question. Plan a change when they ask for something to be made, changed or fixed, or say something that should work does not; when a message asks for both, plan the change.

        - question: null in most cases. Ask only when the request leaves open a product choice that the request, the project notes and the owner's earlier answers do not settle, and a wrong guess would touch money, who may see or do what, data being lost or changed, the shape of the data, outside services, legal expectations or a major way the business works. Then return the single most consequential question: text in the owner's words ("Can customers use more than one location?"), why it matters in one plain sentence, 2 to 4 short options, and the option you recommend. Also say what a wrong guess would touch (touches: money, access for who may see or do what, data_loss, data_shape, outside_services, legal, major_workflow), whether the owner could switch to another option later without losing or rewriting data, money or anyone's access (reversible), and whether the choice is easier to judge once they can try the change (easier_after_seeing). Be honest: you do not decide whether the owner is asked first. When they are not, the change is built on your recommendation and they review that choice with it. Never ask an engineering question (controllers, queues, validation, migrations, policies, tests): decide those by Laravel convention. Never ask what the code or notes already answer. Still return a complete plan built on your recommended option.

        Follow the project's own conventions (for example AGENTS.md) and Laravel's defaults. Do not plan changes to tests/Acceptance: those tests are fixed.
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'answer' => $schema->string()->nullable()->required(),
            'commit_subject' => $schema->string()->required(),
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
            'next' => $schema->array()->items($schema->string())->required(),
            'goal' => $schema->string()->nullable()->required(),
            'question' => $schema->object([
                'text' => $schema->string()->required(),
                'why' => $schema->string()->required(),
                'options' => $schema->array()->items($schema->string())->required(),
                'recommended' => $schema->string()->required(),
                'touches' => $schema->array()->items($schema->string()->enum(array_column(Consequence::cases(), 'value')))->required(),
                'reversible' => $schema->boolean()->required(),
                'easier_after_seeing' => $schema->boolean()->required(),
            ])->withoutAdditionalProperties()->nullable()->required(),
            'data_shape' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'label' => $schema->string()->required(),
                'fields' => $schema->array()->items($schema->object([
                    'name' => $schema->string()->required(),
                    'type' => $schema->string()->enum(array_column(FieldType::cases(), 'value'))->required(),
                    'required' => $schema->boolean()->required(),
                    'choices' => $schema->array()->items($schema->string())->required(),
                    'of' => $schema->string()->nullable()->required(),
                    'label' => $schema->string()->required(),
                ])->withoutAdditionalProperties())->required(),
                'access' => $schema->object(collect(Scaffold::ACTIONS)->mapWithKeys(fn (string $action) => [
                    $action => $schema->string()->enum(Scaffold::WHO)->required(),
                ])->all())->withoutAdditionalProperties()->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
            'steps' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'kind' => $schema->string()->required(),
                'label' => $schema->string()->required(),
                'file' => $schema->string()->required(),
                'symbol' => $schema->string()->required(),
                'detail' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
