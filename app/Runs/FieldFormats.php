<?php

namespace App\Runs;

use App\Actions\Context\RecordDecision;
use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Enums\Consequence;
use App\Scaffolding\FieldType;
use Illuminate\Support\Str;
use NumberFormatter;
use ResourceBundle;

/**
 * Settle each new field's format from what is already decided (§9
 * Formats): the field's own setting, then its area's notes, then the
 * project's. Each setting says where it came from. A country left open is
 * built to accept any country, shown as an assumption, because loose never
 * turns a real customer away. A currency left open is asked, with options
 * made here, not by the model; the answer goes into the project notes'
 * decisions and is not asked again.
 *
 * Notes give formats as plain lines under "## Formats", such as "Phone
 * numbers: Canada" or "Money: Canadian dollars (CAD)". A line may name one
 * field by its label ("Shipping postal code: United States").
 *
 * @phpstan-import-type Record from \App\Scaffolding\Scaffold
 * @phpstan-import-type Field from \App\Scaffolding\Scaffold
 */
class FieldFormats
{
    /**
     * The notes section that holds formats, in the project and each area.
     */
    public const SECTION = 'Formats';

    /**
     * The currency question as the decisions keep it: "- Money: …".
     */
    public const ASKED = 'Money:';

    public const OWN_CURRENCY = 'Each record has its own currency';

    /**
     * Offered after the currencies the app points to, so the owner always
     * has a few to pick from.
     */
    protected const COMMON_CURRENCIES = ['USD', 'EUR', 'GBP'];

    /**
     * What a notes line names, for each kind with a choice to make.
     */
    protected const SUBJECTS = [
        'phone' => '/\bphone/i',
        'postal_code' => '/\b(postal|post ?codes?|postcodes?|zip)\b/i',
        'money' => '/\b(money|currenc|prices?|amounts?)/i',
    ];

    /**
     * Settle the plan's formats, adding an assumption for each one left
     * loose. A money field with no currency known is left without one, for
     * question() to ask about.
     *
     * @param  list<string>  $areas  The keys of the areas the change is about
     * @param  list<array{question: string, asked?: string, answer: string}>  $answers  The owner's answers on this run
     */
    public function settle(Plan $plan, ProjectContext $context, array $areas, array $answers = []): Plan
    {
        $levels = $this->levels($context, $areas, $answers);
        $shape = $plan->dataShape;
        $loose = [];

        foreach ($shape as $r => $record) {
            foreach ($record['fields'] as $f => $field) {
                [$shape[$r]['fields'][$f], $conflict] = $this->settled($field, $levels);

                if ($conflict !== null) {
                    $loose[$field['type']] = $conflict || ($loose[$field['type']] ?? false);
                }
            }
        }

        $assumptions = [];

        foreach ($loose as $type => $conflict) {
            $assumptions[] = new Assumption(
                text: $conflict
                    ? __('The notes name different countries for :things, so I accept them from any country.', ['things' => $this->things($type)])
                    : __('Accepts :things from any country.', ['things' => $this->things($type)]),
                touches: [Consequence::DataShape],
            );
        }

        return $plan->withDataShape($shape)->withAssumptions($assumptions);
    }

    /**
     * Settle one field. The second value says whether it was left loose:
     * null when it was not, true when the notes disagreed.
     *
     * @param  Field  $field
     * @param  list<array{from: string, lines: list<array{string, string}>}>  $levels
     * @return array{0: Field, 1: bool|null}
     */
    protected function settled(array $field, array $levels): array
    {
        $type = FieldType::from($field['type']);
        $format = $field['format'] ?? [];
        $setting = match ($type) {
            FieldType::Phone, FieldType::PostalCode => 'regions',
            FieldType::Money => 'currency',
            default => null,
        };

        // A kind with one standard is decided by it, unless the request
        // asked for a variant of it.
        if ($setting === null) {
            return in_array($type, [FieldType::Url, FieldType::Isbn, FieldType::Country, FieldType::Percentage, FieldType::Pattern], true)
                ? [[...$field, 'format' => [...$format, 'from' => $format['from'] ?? ($format === [] ? 'standard' : 'request')]], null]
                : [$field, null];
        }

        if (isset($format[$setting])) {
            return [[...$field, 'format' => [...$format, 'from' => $format['from'] ?? 'request']], null];
        }

        [$value, $from] = $this->lookUp($field, $levels);

        return match (true) {
            $value !== null => [[...$field, 'format' => [...$format, $setting => $value, 'from' => $from]], null],
            $setting === 'currency' => [$field, null],
            default => [[...$field, 'format' => [...$format, 'regions' => ['any'], 'from' => 'assumed']], $from === 'conflict'],
        };
    }

    /**
     * Get the currency question, or null when every amount's currency is
     * known. The options come from code: the currencies of the countries
     * the app already uses, then a few common ones, then a currency on each
     * record.
     *
     * @return array{text: string, asked: string, why: string, options: list<string>, recommended: string, touches: list<string>, reversible: bool, easier_after_seeing: bool}|null
     */
    public function question(Plan $plan): ?array
    {
        $fields = $this->undecided($plan);

        if ($fields === []) {
            return null;
        }

        $regions = [];

        foreach ($plan->dataShape as $record) {
            foreach ($record['fields'] as $field) {
                $regions = [...$regions, ...array_diff($field['format']['regions'] ?? [], ['any'])];
            }
        }

        $codes = array_values(array_unique([...array_filter(array_map($this->currencyOf(...), array_unique($regions))), ...self::COMMON_CURRENCIES]));
        $options = [...array_map($this->currencyOption(...), $codes), self::OWN_CURRENCY];

        return [
            'text' => count($fields) === 1
                ? __('Which currency is :label in?', ['label' => $fields[0]['label'] ?? Str::headline($fields[0]['name'])])
                : __('Which currency are amounts of money in?'),
            'asked' => self::ASKED,
            'why' => __('Amounts are saved in the currency\'s smallest unit, so changing it later means converting what people saved.'),
            'options' => $options,
            'recommended' => $options[0],
            'touches' => [Consequence::Money->value],
            'reversible' => false,
            'easier_after_seeing' => false,
        ];
    }

    /**
     * Find the owner's answer to the currency question among the run's
     * answers.
     *
     * @param  list<array{question: string, asked?: string, answer: string}>  $answers
     */
    public function answered(array $answers): ?string
    {
        foreach (array_reverse($answers) as $answer) {
            if (($answer['asked'] ?? null) === self::ASKED) {
                return $answer['answer'];
            }
        }

        return null;
    }

    /**
     * Give each amount whose currency nobody named a currency on each
     * record, when the owner cannot be asked, and say so.
     */
    public function unasked(Plan $plan): Plan
    {
        if ($this->undecided($plan) === []) {
            return $plan;
        }

        $shape = array_map(fn (array $record) => [...$record, 'fields' => array_map(
            fn (array $field) => $field['type'] === FieldType::Money->value && ! isset($field['format']['currency'])
                ? [...$field, 'format' => [...$field['format'] ?? [], 'currency' => 'per_record', 'from' => 'assumed']]
                : $field,
            $record['fields'],
        )], $plan->dataShape);

        return $plan->withDataShape($shape)->withAssumptions([new Assumption(
            text: __('Each record keeps its own currency, as no currency was named.'),
            touches: [Consequence::Money],
        )]);
    }

    /**
     * Read a currency from a notes value: an ISO code, a currency's name,
     * or a currency on each record.
     */
    public function currency(string $value): ?string
    {
        if (preg_match('/\b(each|every|per|own)\b.*\b(record|currency)\b/i', $value) === 1) {
            return 'per_record';
        }

        $known = $this->currencies();

        if (preg_match_all('/\b[A-Z]{3}\b/', $value, $codes) > 0) {
            foreach ($codes[0] as $code) {
                if (isset($known[$code])) {
                    return $code;
                }
            }
        }

        $value = Str::lower($value);

        foreach ($known as $code => $names) {
            foreach ($names as $name) {
                if ($name !== '' && str_contains($value, $name)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Read countries from a notes value: codes or names, or any country.
     *
     * @return list<string>|null
     */
    public function regions(string $value): ?array
    {
        if (preg_match('/\b(any|every|all)\b.*\b(country|countries|where)\b|\banywhere\b/i', $value) === 1) {
            return ['any'];
        }

        $names = $this->countries();
        $found = [];

        foreach (preg_split('/\s*(?:,|;|\/|\band\b|\bor\b)\s*/i', $value) ?: [] as $part) {
            $part = trim($part, " \t.()");
            $code = strtoupper($part);
            $found[] = match (true) {
                strlen($part) === 2 && isset($names[$code]) => $code,
                in_array($code, ['UK', 'BRITAIN', 'GREAT BRITAIN'], true) => 'GB',
                in_array($code, ['USA', 'US', 'AMERICA', 'UNITED STATES OF AMERICA'], true) => 'US',
                default => array_search(Str::lower(preg_replace('/^the\s+/i', '', $part) ?? ''), $names, true),
            };
        }

        $found = array_values(array_unique(array_filter($found, is_string(...))));

        return $found === [] ? null : $found;
    }

    /**
     * The places a setting may come from, most specific first: the owner's
     * answers and decisions, the areas, then the project. Each holds its
     * lines as subject and value.
     *
     * @param  list<string>  $areas
     * @param  list<array{question: string, asked?: string, answer: string}>  $answers
     * @return list<array{from: string, lines: list<array{string, string}>}>
     */
    protected function levels(ProjectContext $context, array $areas, array $answers): array
    {
        $project = NotesDocument::parse((string) $context->project);
        $owner = $this->lines((string) $project->section(RecordDecision::SECTION));

        if (($answer = $this->answered($answers)) !== null) {
            $owner[] = ['Money', $answer];
        }

        $area = [];

        foreach ($context->known($areas) as $key) {
            $area = [...$area, ...$this->lines((string) NotesDocument::parse($context->capabilities[$key]->notes)->section(self::SECTION))];
        }

        return [
            ['from' => 'owner', 'lines' => $owner],
            ['from' => 'area', 'lines' => $area],
            ['from' => 'project', 'lines' => $this->lines((string) $project->section(self::SECTION))],
        ];
    }

    /**
     * Find a field's setting at the most specific level that has one. A
     * line naming the field beats one naming its kind. Lines at one level
     * that disagree settle nothing: the answer is a conflict.
     *
     * @param  Field  $field
     * @param  list<array{from: string, lines: list<array{string, string}>}>  $levels
     * @return array{0: list<string>|string|null, 1: string}
     */
    protected function lookUp(array $field, array $levels): array
    {
        $names = array_filter([Str::lower($field['label'] ?? ''), Str::lower(str_replace('_', ' ', $field['name']))]);

        foreach ($levels as $level) {
            foreach ([true, false] as $named) {
                $values = [];

                foreach ($level['lines'] as [$subject, $value]) {
                    $matches = $named
                        ? in_array(Str::lower($subject), $names, true)
                        : preg_match(self::SUBJECTS[$field['type']], $subject) === 1 && ! $this->namesAnother($subject, $names);
                    $read = $matches ? ($field['type'] === FieldType::Money->value ? $this->currency($value) : $this->regions($value)) : null;

                    if ($read !== null) {
                        $values[serialize($read)] = $read;
                    }
                }

                if (count($values) === 1) {
                    return [reset($values), $level['from']];
                }

                if (count($values) > 1) {
                    return [null, 'conflict'];
                }
            }
        }

        return [null, 'none'];
    }

    /**
     * Determine if a subject names some other field ("Shipping postal
     * code"), not every field of the kind ("Postal codes").
     *
     * @param  array<int, string>  $names
     */
    protected function namesAnother(string $subject, array $names): bool
    {
        $words = preg_split('/\s+/', trim(Str::lower(preg_replace(array_values(self::SUBJECTS), '', $subject) ?? ''))) ?: [];
        $words = array_diff($words, ['', 'number', 'numbers', 'code', 'codes', 'of', 'all', 'the']);

        return $words !== [] && ! in_array(Str::lower($subject), $names, true);
    }

    /**
     * Read "Subject: value" lines, with or without a list marker.
     *
     * @return list<array{string, string}>
     */
    protected function lines(string $section): array
    {
        preg_match_all('/^\s*(?:[-*]\s+)?([^:\n]{2,60}):\s*(.+)$/m', $section, $lines, PREG_SET_ORDER);

        return array_map(fn (array $line) => [trim($line[1]), trim($line[2])], $lines);
    }

    /**
     * @return list<Field>
     */
    protected function undecided(Plan $plan): array
    {
        $fields = [];

        foreach ($plan->dataShape as $record) {
            foreach ($record['fields'] as $field) {
                if ($field['type'] === FieldType::Money->value && ! isset($field['format']['currency'])) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    protected function things(string $type): string
    {
        return $type === FieldType::Phone->value ? __('phone numbers') : __('postal codes');
    }

    protected function currencyOf(string $region): ?string
    {
        $code = (new NumberFormatter("en_{$region}", NumberFormatter::CURRENCY))->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        return is_string($code) && isset($this->currencies()[$code]) ? $code : null;
    }

    /**
     * Word a currency as an option the notes can read back: "Canadian
     * dollars (CAD)".
     */
    protected function currencyOption(string $code): string
    {
        $plural = ResourceBundle::create('en', 'ICUDATA-curr')?->get('CurrencyPlurals')?->get($code)?->get('other');

        return (is_string($plural) ? Str::ucfirst($plural) : $code)." ({$code})";
    }

    /**
     * Every currency's code, with its English names in lower case.
     *
     * @return array<string, list<string>>
     */
    protected function currencies(): array
    {
        return once(function () {
            $currencies = [];
            $names = ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies');
            $plurals = ResourceBundle::create('en', 'ICUDATA-curr')?->get('CurrencyPlurals');

            foreach ($names ?? [] as $code => $name) {
                if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1) {
                    $other = $plurals?->get($code)?->get('other');
                    $currencies[$code] = array_values(array_filter([Str::lower((string) ($name[1] ?? '')), is_string($other) ? Str::lower($other) : '']));
                }
            }

            return $currencies;
        });
    }

    /**
     * Every country's English name in lower case, by code.
     *
     * @return array<string, string>
     */
    protected function countries(): array
    {
        return once(function () {
            $countries = [];

            foreach (ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries') ?? [] as $code => $name) {
                if (is_string($code) && is_string($name) && preg_match('/^[A-Z]{2}$/', $code) === 1) {
                    $countries[$code] = Str::lower($name);
                }
            }

            return $countries;
        });
    }
}
