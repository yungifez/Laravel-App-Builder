<?php

namespace Tests\Unit;

use App\Features\InputProbes;
use Tests\TestCase;

class InputProbesTest extends TestCase
{
    protected const SOURCE = 'app/Http/Requests/StoreBookingRequest.php';

    /**
     * The form that adds a booking.
     *
     * @return list<array{method: string, uri: string, action: string}>
     */
    protected function routes(): array
    {
        return [['method' => 'POST', 'uri' => 'bookings', 'action' => 'App\Http\Controllers\BookingController@store']];
    }

    /**
     * The booking form's rules as the first test notes them.
     *
     * @param  array<string, list<string>>  $more
     * @return array<int, array{id: int, status: int, source: string, fields: array<string, list<string>>, reason: string|null}>
     */
    protected function rules(array $more = [], ?string $reason = null): array
    {
        return [0 => ['id' => 0, 'status' => 302, 'source' => self::SOURCE, 'reason' => $reason, 'fields' => [
            'title' => ['required', 'string', 'max:20'],
            'starts_on' => ['required', 'date', 'after:today'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'kind' => ['required', 'in:single,double'],
            'nickname' => ['nullable', 'string', 'max:30'],
            ...$more,
        ]]];
    }

    public function test_only_forms_on_a_touched_controller_are_probed(): void
    {
        $route = fn (string $method, string $uri, string $action) => ['domain' => null, 'method' => $method, 'uri' => $uri, 'name' => null, 'action' => $action, 'middleware' => ['web']];
        $list = (string) json_encode([
            $route('GET|HEAD', 'bookings', 'App\Http\Controllers\BookingController@index'),
            $route('POST', 'bookings', 'App\Http\Controllers\BookingController@store'),
            $route('PUT|PATCH', 'bookings/{booking}', 'App\Http\Controllers\BookingController@update'),
            $route('POST', 'rooms', 'App\Http\Controllers\RoomController@store'),
            $route('POST', 'logout', 'Closure'),
        ]);

        $this->assertSame([
            ['method' => 'POST', 'uri' => 'bookings', 'action' => 'App\Http\Controllers\BookingController@store'],
            ['method' => 'PUT', 'uri' => 'bookings/{booking}', 'action' => 'App\Http\Controllers\BookingController@update'],
        ], InputProbes::routes($list, ['App\Http\Controllers\BookingController']));
        $this->assertSame([], InputProbes::routes('not json', ['App\Http\Controllers\BookingController']));
    }

    public function test_the_written_tests_are_plain_php_that_report_each_send(): void
    {
        $rules = InputProbes::rulesTest($this->routes(), 'storage/logs/access/input-rules.jsonl');
        $test = InputProbes::test($this->routes(), InputProbes::plan($this->routes(), $this->rules(), 40), 'storage/logs/access/inputs.jsonl');

        $this->assertNotFalse(token_get_all($rules, TOKEN_PARSE));
        $this->assertNotFalse(token_get_all($test, TOKEN_PARSE));
        $this->assertStringContainsString("\$this->rules(0, 'POST', 'bookings', 'App\\\\Http\\\\Controllers\\\\BookingController@store');", $rules);
        $this->assertStringContainsString("base_path('storage/logs/access/input-rules.jsonl')", $rules);
        $this->assertStringContainsString("\$this->probe('form', 0, 'POST', 'bookings', array (   'title' => 'Probe-", $test);
        $this->assertStringContainsString("base_path('storage/logs/access/inputs.jsonl')", $test);
    }

    public function test_the_rules_report_is_read_line_by_line(): void
    {
        $rules = InputProbes::rules(implode("\n", [
            '{"id":0,"status":302,"source":"app/Http/Requests/StoreBookingRequest.php","fields":{"title":["required","max:20",7]},"reason":null}',
            '{"id":1,"status":0,"source":"","fields":[],"reason":"needs_record:booking"}',
            'not json',
            '{"status":302}',
        ]));

        $this->assertSame([0, 1], array_keys($rules));
        $this->assertSame(['title' => ['required', 'max:20']], $rules[0]['fields']);
        $this->assertSame('needs_record:booking', $rules[1]['reason']);
    }

    public function test_each_field_is_changed_alone_on_a_form_that_should_pass_whole(): void
    {
        $planned = InputProbes::plan($this->routes(), $this->rules(), 40);
        $form = $planned['baselines'][0];

        // Dates keep their order: ends after starts, starts after today.
        $this->assertSame(30, $form['starts_on']['@date']);
        $this->assertSame(31, $form['ends_on']['@date']);
        $this->assertSame('single', $form['kind']);

        $says = array_column($planned['probes'], 'says');
        $this->assertSame(['title left out', 'starts_on left out', 'ends_on left out', 'kind left out', 'nickname left out'], array_slice($says, 0, 5), 'leaving fields out is tried first');
        $this->assertContains('title 21 characters long (max:20)', $says);
        $this->assertContains('kind outside its choices', $says);
        $this->assertContains('ends_on on the wrong side of starts_on (after:starts_on)', $says);

        foreach ($planned['probes'] as $probe) {
            $changed = array_keys(array_diff_key($form, $probe['payload']) + array_filter($probe['payload'], fn (mixed $value, string $field) => ($form[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            $this->assertSame([$probe['field']], $changed, "{$probe['says']} changes one field only");
        }

        $byField = array_column(array_filter($planned['probes'], fn (array $probe) => $probe['says'] === 'nickname left out'), 'expect');
        $this->assertSame(['accept'], $byField, 'an optional field may be left out');
        $this->assertSame(['accept'], array_column(array_filter($planned['probes'], fn (array $probe) => $probe['says'] === 'title 20 characters long (max:20)'), 'expect'));
        $this->assertSame(20, strlen(array_values(array_filter($planned['probes'], fn (array $probe) => $probe['says'] === 'title 20 characters long (max:20)'))[0]['payload']['title']));

        $this->assertCount(5, InputProbes::plan($this->routes(), $this->rules(), 5)['probes'], 'the limit holds');
    }

    public function test_fields_linked_by_a_rule_are_combined_and_code_rules_count_as_coverage(): void
    {
        $planned = InputProbes::plan($this->routes(), $this->rules([
            'note' => ['nullable', 'string', 'required_if:kind,double'],
            'code' => ['required', 'custom:Uppercase'],
            'rooms.*' => ['integer'],
        ]), 40);

        $note = array_values(array_filter($planned['probes'], fn (array $probe) => $probe['field'] === 'note'));
        $this->assertCount(1, $note);
        $this->assertSame('note left out while kind is double (required_if)', $note[0]['says']);
        $this->assertSame('double', $note[0]['payload']['kind']);
        $this->assertArrayNotHasKey('note', $note[0]['payload']);

        $this->assertSame(['code left out'], array_column(array_filter($planned['probes'], fn (array $probe) => $probe['field'] === 'code'), 'says'), 'a rule written as code is tried only for presence');
        $this->assertSame([
            ['route' => 0, 'field' => 'rooms.*', 'reason' => 'in_a_list'],
            ['route' => 0, 'field' => 'note', 'reason' => 'conditional'],
            ['route' => 0, 'field' => 'code', 'reason' => 'custom'],
        ], $planned['coverage']);
    }

    public function test_a_form_that_cannot_be_filled_in_or_reached_is_coverage_only(): void
    {
        $unfilled = InputProbes::plan($this->routes(), $this->rules(['code' => ['required', 'regex:/^[A-Z]{3}$/']]), 40);
        $unreached = InputProbes::plan($this->routes(), $this->rules(reason: 'no_user_factory'), 40);

        $this->assertSame(['baselines' => [], 'probes' => [], 'coverage' => [['route' => 0, 'field' => '', 'reason' => 'cannot_fill:code']]], $unfilled);
        $this->assertSame(['baselines' => [], 'probes' => [], 'coverage' => [['route' => 0, 'field' => '', 'reason' => 'no_user_factory']]], $unreached);
        $this->assertSame([['route' => 0, 'field' => '', 'reason' => 'not_run']], InputProbes::plan($this->routes(), [], 40)['coverage']);
    }

    /**
     * Measure the booking form: the whole form came back as given, and
     * each probe as its line says.
     *
     * @param  array<string, array{status: int, errors?: list<string>, exception?: string|null}>  $answers  By what the probe says
     * @param  array<string, array{new: bool, lines: list<string>}>  $changed
     * @param  array<string, mixed>  $form  How the whole form came back
     * @return array{findings: list<array{route: string, field: string, says: string, outcome: string, exception: string|null}>, existing: list<array{route: string, field: string, says: string, outcome: string, exception: string|null}>, coverage: list<array{route: int, field: string, reason: string}>, tried: int, forms: int}
     */
    protected function measure(array $answers, array $changed, array $form = ['status' => 302, 'errors' => []]): array
    {
        $planned = InputProbes::plan($this->routes(), $this->rules(), 40);
        $lines = [json_encode(['kind' => 'form', 'id' => 0, 'exception' => null, 'reason' => null, ...$form])];

        foreach ($planned['probes'] as $id => $probe) {
            $answer = $answers[$probe['says']] ?? ['status' => 302, 'errors' => $probe['expect'] === 'refuse' ? [$probe['field']] : []];
            $lines[] = json_encode(['kind' => 'probe', 'id' => $id, 'errors' => [], 'exception' => null, 'reason' => null, ...$answer]);
        }

        return InputProbes::measure($this->routes(), $this->rules(), $planned, InputProbes::parse(implode("\n", $lines)), $changed);
    }

    public function test_a_wrong_value_taken_on_a_new_form_is_a_finding(): void
    {
        $measured = $this->measure([
            'title 21 characters long (max:20)' => ['status' => 302],
            'nickname left out' => ['status' => 500, 'exception' => 'ErrorException'],
            'title 20 characters long (max:20)' => ['status' => 302, 'errors' => ['title']],
            // Turned down for another field: proves nothing about kind.
            'kind outside its choices' => ['status' => 302, 'errors' => ['title']],
        ], [self::SOURCE => ['new' => true, 'lines' => []]]);

        $this->assertSame([
            'POST /bookings broke (ErrorException) on nickname left out. It should answer with a message.',
            'POST /bookings accepted title 21 characters long (max:20). It should be turned down with a message.',
            'POST /bookings turned down title 20 characters long (max:20), which its rules allow.',
        ], array_slice(explode("\n", InputProbes::describe($this->routes(), $measured)), 0, 3));
        $this->assertCount(3, $measured['findings']);
        $this->assertSame([], $measured['existing']);
        $this->assertSame(16, $measured['tried']);
        $this->assertContains(['route' => 0, 'field' => 'kind', 'reason' => 'other_field'], $measured['coverage']);
    }

    public function test_a_wrong_value_on_a_field_the_change_did_not_add_is_reported_as_existing(): void
    {
        $answers = [
            'title 21 characters long (max:20)' => ['status' => 302],
            'nickname 31 characters long (max:30)' => ['status' => 302],
        ];

        // The change touched the rules file but only the nickname line.
        $measured = $this->measure($answers, [self::SOURCE => ['new' => false, 'lines' => ["            'nickname' => ['nullable', 'string', 'max:30'],"]]]);

        $this->assertSame(['nickname'], array_column($measured['findings'], 'field'));
        $this->assertSame(['title'], array_column($measured['existing'], 'field'));

        $untouched = $this->measure($answers, []);
        $this->assertSame([], $untouched['findings']);
        $this->assertSame(['title', 'nickname'], array_column($untouched['existing'], 'field'));
        $this->assertSame(implode("\n", [
            'Already so before this change, so not sent back:',
            '- POST /bookings accepted title 21 characters long (max:20). It should be turned down with a message.',
            '- POST /bookings accepted nickname 31 characters long (max:30). It should be turned down with a message.',
            'Tried 17 values on 1 form.',
        ]), InputProbes::describe($this->routes(), $untouched));
    }

    public function test_probes_on_a_form_that_was_not_accepted_whole_are_never_findings(): void
    {
        $changed = [self::SOURCE => ['new' => true, 'lines' => []]];
        $refused = $this->measure(['title 21 characters long (max:20)' => ['status' => 302]], $changed, ['status' => 302, 'errors' => ['kind']]);
        $broke = $this->measure([], $changed, ['status' => 500, 'errors' => [], 'exception' => 'QueryException']);

        $this->assertSame([[], [], 0, 0], [$refused['findings'], $refused['existing'], $refused['tried'], $refused['forms']]);
        $this->assertSame([['route' => 0, 'field' => '', 'reason' => 'form_refused:kind']], $refused['coverage']);
        $this->assertSame([['route' => 0, 'field' => '', 'reason' => 'form_broke:QueryException']], $broke['coverage']);
        $this->assertSame(implode("\n", [
            'Tried 0 values on 0 forms.',
            'Not fully tried:',
            '- POST /bookings a filled-in form was turned down (kind)',
        ]), InputProbes::describe($this->routes(), $refused));
    }

    public function test_the_change_is_read_across_its_rounds(): void
    {
        $added = implode("\n", [
            'diff --git a/app/Http/Requests/StoreBookingRequest.php b/app/Http/Requests/StoreBookingRequest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/app/Http/Requests/StoreBookingRequest.php',
            '@@ -0,0 +1,2 @@',
            '+<?php',
            "+'title' => ['required'],",
        ]);
        $edited = implode("\n", [
            'diff --git a/app/Http/Requests/StoreBookingRequest.php b/app/Http/Requests/StoreBookingRequest.php',
            '--- a/app/Http/Requests/StoreBookingRequest.php',
            '+++ b/app/Http/Requests/StoreBookingRequest.php',
            '@@ -2 +2 @@',
            "-'title' => ['required'],",
            "+'title' => ['required', 'max:20'],",
        ]);

        $this->assertSame([self::SOURCE => ['new' => true, 'lines' => ['<?php', "'title' => ['required'],", "'title' => ['required', 'max:20'],"]]], InputProbes::changed([$added, $edited]));
        $this->assertSame([self::SOURCE => ['new' => false, 'lines' => ["'title' => ['required', 'max:20'],"]]], InputProbes::changed([null, $edited]));
        $this->assertSame([], InputProbes::changed([null]));
    }
}
