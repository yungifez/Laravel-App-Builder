<?php

namespace App\Runs;

use App\Enums\Consequence;
use App\Scaffolding\FieldType;
use App\Scaffolding\ShapeWording;

/**
 * Show the owner a new record's shape before it is built, when it is hard
 * to change later (§8). A required detail or a fixed list of choices
 * becomes a rule in the database: once people have saved records, changing
 * it means moving what they saved. A shape with neither builds without
 * asking.
 *
 * The question is made in code from the shape, through the same pause and
 * answer as the planner's questions. Each answer is one the code can carry
 * out on its own, so nothing depends on the planner reading it. The run
 * plans again after the answer. The answer holds for a shape worded the
 * same, so a shape the planner changed meanwhile is asked about again.
 *
 * @phpstan-import-type Record from \App\Scaffolding\Scaffold
 * @phpstan-import-type Field from \App\Scaffolding\Scaffold
 */
class ShapeQuestion
{
    public const YES = 'Yes, set it up this way';

    public const OPTIONAL = 'Make every detail optional';

    public const TYPED = 'Let people type their own answers instead of picking';

    public function __construct(private ShapeWording $wording) {}

    /**
     * Get the question to ask about the plan's shape, or null when the shape
     * is easy to change later.
     *
     * The question is one line; the few details that are hard to undo show
     * under it, and the whole shape waits behind "The plan".
     *
     * @return array{text: string, asked: string, glance: list<string>, details: list<string>, why: string, options: list<string>, recommended: string, touches: list<string>, reversible: bool, easier_after_seeing: bool}|null
     */
    public function for(Plan $plan): ?array
    {
        $options = [self::YES];

        if ($this->has($plan->dataShape, fn (array $field) => $field['required'] && ! $this->kept($field))) {
            $options[] = self::OPTIONAL;
        }

        if ($this->has($plan->dataShape, fn (array $field) => $field['type'] === FieldType::Choice->value)) {
            $options[] = self::TYPED;
        }

        if (count($options) < 2) {
            return null;
        }

        return [
            'text' => __('Shall I set up :things like this?', ['things' => $this->wording->nouns($plan->dataShape)]),
            'asked' => $this->asked($plan),
            'glance' => $this->wording->glance($plan->dataShape),
            'details' => $this->wording->describe($plan->dataShape),
            'why' => __('Once people have saved these, changing what must be filled in or picked means moving what they saved.'),
            'options' => $options,
            'recommended' => self::YES,
            'touches' => [Consequence::DataShape->value],
            'reversible' => false,
            'easier_after_seeing' => false,
        ];
    }

    /**
     * Find the owner's answer for this shape among the run's answers.
     *
     * @param  list<array{question: string, asked?: string, answer: string, decided_by?: string}>  $answers
     */
    public function answered(Plan $plan, array $answers): ?string
    {
        $asked = $this->asked($plan);

        foreach (array_reverse($answers) as $answer) {
            if (($answer['asked'] ?? null) === $asked) {
                return $answer['answer'];
            }
        }

        return null;
    }

    /**
     * Build the shape as the answer says.
     */
    public function apply(Plan $plan, string $answer): Plan
    {
        $shape = array_map(fn (array $record) => [
            ...$record,
            'fields' => array_map(fn (array $field) => match (true) {
                $answer === self::OPTIONAL && ! $this->kept($field) => [...$field, 'required' => false],
                $answer === self::TYPED && $field['type'] === FieldType::Choice->value => [...$field, 'type' => FieldType::String->value, 'choices' => []],
                default => $field,
            }, $record['fields']),
        ], $plan->dataShape);

        return $plan->withDataShape($shape);
    }

    /**
     * The question asked is the whole shape in the owner's words, so one
     * worded the same is the same question.
     */
    protected function asked(Plan $plan): string
    {
        return implode(' ', $this->wording->describe($plan->dataShape));
    }

    /**
     * Determine if a field stays as it is whatever the answer: a yes or no
     * starts as no, and the person who added a record is always known.
     *
     * @param  array{type: string, of: string|null}  $field
     */
    protected function kept(array $field): bool
    {
        return $field['type'] === FieldType::Boolean->value
            || ($field['type'] === FieldType::BelongsTo->value && $field['of'] === 'User');
    }

    /**
     * @param  list<Record>  $shape
     * @param  callable(Field): bool  $matches
     */
    protected function has(array $shape, callable $matches): bool
    {
        foreach ($shape as $record) {
            foreach ($record['fields'] as $field) {
                if ($matches($field)) {
                    return true;
                }
            }
        }

        return false;
    }
}
