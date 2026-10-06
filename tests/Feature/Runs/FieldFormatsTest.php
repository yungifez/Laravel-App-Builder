<?php

namespace Tests\Feature\Runs;

use App\Context\Capability;
use App\Context\ProjectContext;
use App\Runs\FieldFormats;
use App\Runs\Plan;
use Tests\TestCase;

/**
 * Each new field's format comes from what is already decided: the field,
 * then its area, then the project, and says where it came from (§9).
 */
class FieldFormatsTest extends TestCase
{
    public function test_a_format_comes_from_the_field_then_the_area_then_the_project()
    {
        $context = $this->context(
            "## Formats\n\n- Phone numbers: Canada\n- Postal codes: Canada\n",
            ['shipping' => "## Formats\n\nPostal codes: United States\n"],
        );
        $plan = $this->plan([
            $this->field('phone', 'phone'),
            $this->field('postcode', 'postal_code'),
            $this->field('mobile', 'phone', ['regions' => ['GB']]),
            $this->field('isbn', 'isbn'),
            $this->field('title', 'string'),
        ]);

        $settled = app(FieldFormats::class)->settle($plan, $context, ['shipping']);

        $this->assertSame([
            'phone' => ['regions' => ['CA'], 'from' => 'project'],
            'postcode' => ['regions' => ['US'], 'from' => 'area'],
            'mobile' => ['regions' => ['GB'], 'from' => 'request'],
            'isbn' => ['from' => 'standard'],
            'title' => null,
        ], $this->formats($settled));
        $this->assertSame([], $settled->assumptions);

        // An area the change is not about says nothing.
        $this->assertSame(['regions' => ['CA'], 'from' => 'project'], $this->formats(app(FieldFormats::class)->settle($plan, $context, []))['postcode']);
    }

    public function test_a_line_naming_one_field_beats_its_kind_and_the_owners_decision_beats_the_notes()
    {
        $context = $this->context(
            "## Formats\n\n- Postal codes: CA\n- Shipping postal code: US, GB\n- Money: euros\n\n## Decisions\n\n- Money: Canadian dollars (CAD)\n",
        );
        $plan = $this->plan([
            $this->field('shipping_postcode', 'postal_code', label: 'shipping postal code'),
            $this->field('billing_postcode', 'postal_code', label: 'billing postal code'),
            $this->field('price', 'money'),
        ]);

        $formats = $this->formats(app(FieldFormats::class)->settle($plan, $context, []));

        $this->assertSame(['regions' => ['US', 'GB'], 'from' => 'project'], $formats['shipping_postcode']);
        $this->assertSame(['regions' => ['CA'], 'from' => 'project'], $formats['billing_postcode']);
        $this->assertSame(['currency' => 'CAD', 'from' => 'owner'], $formats['price']);
    }

    public function test_a_country_left_open_or_in_dispute_is_built_loose_and_shown_at_a_glance()
    {
        $plan = $this->plan([$this->field('phone', 'phone'), $this->field('other_phone', 'phone'), $this->field('postcode', 'postal_code')]);

        $settled = app(FieldFormats::class)->settle($plan, $this->context("## Formats\n\n- Phone numbers: soon\n"), []);

        $this->assertSame(['regions' => ['any'], 'from' => 'assumed'], $this->formats($settled)['phone']);
        $this->assertSame(['Accepts phone numbers from any country.', 'Accepts postal codes from any country.'], $settled->assumptionTexts());
        $this->assertSame('glance', $settled->assumptions[0]->level()->value);

        // Two lines at one level that disagree settle nothing.
        $disputed = app(FieldFormats::class)->settle($plan, $this->context("## Formats\n\n- Postal codes: Canada\n- Postcodes: Nigeria\n"), []);

        $this->assertSame(['regions' => ['any'], 'from' => 'assumed'], $this->formats($disputed)['postcode']);
        $this->assertContains('The notes name different countries for postal codes, so I accept them from any country.', $disputed->assumptionTexts());
    }

    public function test_a_currency_nobody_named_is_asked_with_options_made_from_the_app()
    {
        $formats = app(FieldFormats::class);
        $plan = $formats->settle($this->plan([$this->field('phone', 'phone', ['regions' => ['CA']]), $this->field('price', 'money', label: 'the price')]), $this->context(''), []);

        $question = $formats->question($plan);

        $this->assertSame('Which currency is the price in?', $question['text']);
        $this->assertSame(FieldFormats::ASKED, $question['asked']);
        $this->assertSame(['Canadian dollars (CAD)', 'US dollars (USD)', 'Euros (EUR)', 'British pounds (GBP)', FieldFormats::OWN_CURRENCY], $question['options']);
        $this->assertSame('Canadian dollars (CAD)', $question['recommended']);
        $this->assertSame(['money'], $question['touches']);

        // The answer settles it, and reads back from the decision it writes.
        $answered = $formats->settle($this->plan([$this->field('price', 'money')]), $this->context(''), [], [['question' => $question['text'], 'asked' => FieldFormats::ASKED, 'answer' => FieldFormats::OWN_CURRENCY]]);
        $this->assertSame(['currency' => 'per_record', 'from' => 'owner'], $this->formats($answered)['price']);
        $this->assertNull($formats->question($answered));
        $this->assertSame('CAD', $formats->currency('Canadian dollars (CAD)'));
    }

    public function test_a_currency_that_cannot_be_asked_is_kept_on_each_record_and_said()
    {
        $formats = app(FieldFormats::class);
        $plan = $formats->settle($this->plan([$this->field('price', 'money')]), $this->context("## Formats\n\nMoney: pebbles\n"), []);

        $unasked = $formats->unasked($plan);

        $this->assertSame(['currency' => 'per_record', 'from' => 'assumed'], $this->formats($unasked)['price']);
        $this->assertSame(['Each record keeps its own currency, as no currency was named.'], $unasked->assumptionTexts());
        $this->assertSame(['money'], $unasked->assumptions[0]->toArray()['touches']);
        $this->assertSame($unasked, $formats->unasked($unasked), 'nothing left to decide');
    }

    public function test_notes_values_read_as_codes_names_or_any_country()
    {
        $formats = app(FieldFormats::class);

        $this->assertSame(['CA', 'US'], $formats->regions('Canada and the United States'));
        $this->assertSame(['GB', 'NG'], $formats->regions('UK, ng'));
        $this->assertSame(['any'], $formats->regions('any country'));
        $this->assertNull($formats->regions('to be decided'));
        $this->assertSame('JPY', $formats->currency('Japanese yen'));
        $this->assertSame('per_record', $formats->currency('each record has its own'));
        $this->assertNull($formats->currency('ABC'));
    }

    /**
     * @param  array<string, string>  $areas  Area notes by key
     */
    protected function context(string $project, array $areas = []): ProjectContext
    {
        return new ProjectContext(
            project: "# Bright Cleaning\n\n{$project}",
            capabilities: collect($areas)->map(fn (string $notes, string $key) => new Capability(key: $key, name: ucfirst($key), notes: $notes))->all(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    protected function plan(array $fields): Plan
    {
        return Plan::fromArray([
            'summary' => 'Keep orders.',
            'acceptance_criteria' => [],
            'assumptions' => [],
            'tasks' => [],
            'steps' => [],
            'acceptance' => [],
            'solution_key' => null,
            'data_shape' => [['name' => 'Order', 'fields' => $fields, 'access' => null]],
            'cases' => [],
            'written_tests' => [],
            'written_files' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $format
     * @return array<string, mixed>
     */
    protected function field(string $name, string $type, array $format = [], ?string $label = null): array
    {
        return ['name' => $name, 'type' => $type, 'required' => true, 'choices' => [], 'of' => null, ...($label === null ? [] : ['label' => $label]), ...($format === [] ? [] : ['format' => $format])];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formats(Plan $plan): array
    {
        return collect($plan->dataShape[0]['fields'])->mapWithKeys(fn (array $field) => [$field['name'] => $field['format'] ?? null])->all();
    }
}
