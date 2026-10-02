<?php

namespace App\Scaffolding;

use Illuminate\Support\Str;

/**
 * Say in plain words what a data shape keeps and who may do what with it,
 * so the owner sees it with the change, like any decision made for them
 * (§9). The words come from the planner's labels; the sentences around them
 * are fixed, so no code name reaches the owner.
 *
 * @phpstan-import-type Record from Scaffold
 * @phpstan-import-type Field from Scaffold
 */
class ShapeWording
{
    /**
     * Describe each record in one or two sentences.
     *
     * @param  list<Record>  $records
     * @return list<string>
     */
    public function describe(array $records): array
    {
        $sentences = [];

        foreach ($records as $record) {
            $noun = $record['label'] ?? $this->words($record['name']);
            $sentence = "For each {$noun} I keep: ".$this->list(array_map($this->field(...), $record['fields']), 'and').'.';

            if (($record['access'] ?? null) !== null) {
                $sentence .= ' '.$this->access($record['access'], $noun);
            }

            $sentences[] = $sentence;
        }

        return $sentences;
    }

    /**
     * @param  Field  $field
     */
    protected function field(array $field): string
    {
        $label = $field['label'] ?? $this->fallback($field);

        if ($field['type'] === FieldType::Choice->value) {
            $label .= ' ('.$this->list(array_map($this->words(...), $field['choices']), 'or').')';
        }

        // A yes or no is never missing: it starts as no.
        return $field['required'] || $field['type'] === FieldType::Boolean->value ? $label : "{$label} if there is one";
    }

    /**
     * Name a field the planner gave no label, from its name.
     *
     * @param  Field  $field
     */
    protected function fallback(array $field): string
    {
        if ($field['type'] === FieldType::BelongsTo->value && $field['of'] === 'User') {
            return 'who added it';
        }

        if ($field['type'] === FieldType::Boolean->value) {
            return 'whether it is '.$this->words((string) preg_replace('/^is_/', '', $field['name']));
        }

        return 'the '.$this->words((string) preg_replace('/_(at|on|id)$/', '', $field['name']));
    }

    /**
     * Say who may do what, grouped by who.
     *
     * @param  array{view: string, create: string, update: string, delete: string}  $access
     */
    protected function access(array $access, string $noun): string
    {
        $verbs = ['view' => 'see', 'create' => 'add', 'update' => 'change', 'delete' => 'remove'];
        $plural = Str::plural($noun);
        $groups = [];

        foreach ($verbs as $action => $verb) {
            // Nobody has added a record that does not exist yet.
            $who = $action === 'create' && $access[$action] === 'creator' ? 'signed_in' : $access[$action];
            $groups[$who][] = $verb;
        }

        $sentences = [];

        foreach (['everyone' => "Anyone can %s {$plural}.", 'signed_in' => "Anyone signed in can %s {$plural}.", 'creator' => "Only the person who added a {$noun} can %s it."] as $who => $sentence) {
            if (isset($groups[$who])) {
                $sentences[] = sprintf($sentence, $this->list($groups[$who], 'or'));
            }
        }

        return implode(' ', $sentences);
    }

    /**
     * @param  list<string>  $items
     */
    protected function list(array $items, string $last): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        return implode(', ', array_slice($items, 0, -1))." {$last} ".end($items);
    }

    protected function words(string $name): string
    {
        return str_replace('_', ' ', Str::snake($name));
    }
}
