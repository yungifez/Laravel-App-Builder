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
        ]), 40);

        $note = array_values(array_filter($planned['probes'], fn (array $probe) => $probe['field'] === 'note'));
        $this->assertCount(1, $note);
        $this->assertSame('note left out while kind is double (required_if)', $note[0]['says']);
        $this->assertSame('double', $note[0]['payload']['kind']);
        $this->assertArrayNotHasKey('note', $note[0]['payload']);

        $this->assertSame(['code left out'], array_column(array_filter($planned['probes'], fn (array $probe) => $probe['field'] === 'code'), 'says'), 'a rule written as code is tried only for presence');
        $this->assertSame([
            ['route' => 0, 'field' => 'note', 'reason' => 'conditional'],
            ['route' => 0, 'field' => 'code', 'reason' => 'custom'],
        ], $planned['coverage']);
    }

    /**
     * Get the probes of a plan by what each says.
     *
     * @param  array{probes: list<array{route: int, field: string, key: string, expect: string, payload: array<string, mixed>, says: string}>}  $planned
     * @return array<string, array{route: int, field: string, key: string, expect: string, payload: array<string, mixed>, says: string}>
     */
    protected function bySays(array $planned): array
    {
        return array_column($planned['probes'], null, 'says');
    }

    public function test_each_item_field_of_a_list_is_tried_on_the_first_item(): void
    {
        $planned = InputProbes::plan($this->routes(), $this->rules([
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.name' => ['required', 'string', 'max:10'],
            'rooms.*.beds' => ['required', 'integer', 'between:1,4'],
            'tags.*' => ['string', 'max:5'],
        ]), 80);
        $probes = $this->bySays($planned);

        $this->assertSame([['name' => $planned['baselines'][0]['rooms'][0]['name'], 'beds' => 1]], $planned['baselines'][0]['rooms']);
        $this->assertSame(['Probe'], $planned['baselines'][0]['tags'], 'a list with only item rules gets one item');

        $this->assertSame(['rooms.0.beds', 'rooms.*.beds', 'refuse'], [$probes['rooms.*.beds of 5 (between:1,4)']['field'], $probes['rooms.*.beds of 5 (between:1,4)']['key'], $probes['rooms.*.beds of 5 (between:1,4)']['expect']]);
        $this->assertSame(5, $probes['rooms.*.beds of 5 (between:1,4)']['payload']['rooms'][0]['beds']);
        $this->assertSame([[]], array_map(fn (array $room) => array_diff_key($room, ['name' => true, 'beds' => true]), $probes['rooms.*.name left out']['payload']['rooms']));
        $this->assertArrayNotHasKey('name', $probes['rooms.*.name left out']['payload']['rooms'][0]);
        $this->assertSame([[], 'refuse'], [$probes['rooms as an empty list']['payload']['rooms'], $probes['rooms as an empty list']['expect']]);
        $this->assertSame(['not-a-list', 'refuse'], [$probes['rooms as text instead of a list']['payload']['rooms'], $probes['rooms as text instead of a list']['expect']]);
        $this->assertSame('tags.0', $probes['tags.* 6 characters long (max:5)']['field']);
        $this->assertArrayNotHasKey('tags.* left out', $probes, 'an item left out is the list made shorter');
        $this->assertArrayNotHasKey('tags as an empty list', $probes, 'a list with no rules of its own is not judged');
    }

    public function test_a_list_that_may_be_empty_is_expected_to_take_an_empty_list(): void
    {
        $probes = $this->bySays(InputProbes::plan($this->routes(), $this->rules([
            'tags' => ['present', 'array'],
            'labels' => ['nullable', 'array'],
            'seats' => ['required', 'array', 'min:2'],
            'seats.*' => ['integer'],
        ]), 80));
        $planned = InputProbes::plan($this->routes(), $this->rules(['seats' => ['required', 'array', 'min:2'], 'seats.*' => ['integer']]), 80);

        $this->assertSame('accept', $probes['tags as an empty list']['expect'], 'present lets an empty list through');
        $this->assertSame('refuse', $probes['tags left out']['expect']);
        $this->assertSame(['accept', 'accept'], [$probes['labels as an empty list']['expect'], $probes['labels left out']['expect']]);
        $this->assertSame('refuse', $probes['seats as an empty list']['expect']);
        $this->assertSame([1, 1], $planned['baselines'][0]['seats'], 'enough items for the list to pass');

        // The change named the item field on an added line, so it blocks.
        $plan = InputProbes::plan($this->routes(), $this->rules(['rooms.*.name' => ['required', 'string', 'max:10']]), 80);
        $id = array_search('rooms.*.name 11 characters long (max:10)', array_column($plan['probes'], 'says'), true);
        $lines = [json_encode(['kind' => 'form', 'id' => 0, 'status' => 302, 'errors' => [], 'exception' => null, 'reason' => null])];

        foreach ($plan['probes'] as $index => $probe) {
            $lines[] = json_encode(['kind' => 'probe', 'id' => $index, 'status' => 302, 'errors' => $index === $id || $probe['expect'] === 'accept' ? [] : [$probe['field']], 'exception' => null, 'reason' => null]);
        }

        $measured = InputProbes::measure($this->routes(), $this->rules(['rooms.*.name' => ['required', 'string', 'max:10']]), $plan, InputProbes::parse(implode("\n", $lines)), [self::SOURCE => ['new' => false, 'lines' => ["            'rooms.*.name' => ['required', 'string', 'max:10'],"]]]);
        $this->assertSame(['rooms.0.name'], array_column($measured['findings'], 'field'));
        $this->assertSame([], $measured['existing']);
    }

    public function test_a_list_that_cannot_be_filled_in_stops_or_is_left_out(): void
    {
        $keyed = InputProbes::plan($this->routes(), $this->rules(['guest' => ['required', 'array:name,email']]), 40);
        $optional = InputProbes::plan($this->routes(), $this->rules(['guest' => ['nullable', 'array:name,email'], 'rooms.*.code' => ['nullable', 'regex:/^[A-Z]+$/'], 'rooms.*.size' => ['required_with:rooms.*.code', 'integer']]), 40);

        $this->assertSame([['route' => 0, 'field' => '', 'reason' => 'cannot_fill:guest']], $keyed['coverage']);
        $this->assertArrayNotHasKey('guest', $optional['baselines'][0]);
        $this->assertSame([
            ['route' => 0, 'field' => 'rooms.*.code', 'reason' => 'cannot_fill'],
            ['route' => 0, 'field' => 'rooms.*.size', 'reason' => 'conditional'],
        ], $optional['coverage']);
        $this->assertSame([], array_filter($optional['probes'], fn (array $probe) => str_starts_with($probe['field'], 'rooms')), 'a linked field inside a list is not combined');
    }

    public function test_a_file_is_tried_missing_of_the_wrong_type_and_past_its_size(): void
    {
        $planned = InputProbes::plan($this->routes(), $this->rules(['plan' => ['required', 'file', 'mimes:pdf', 'max:100'], 'photo' => ['nullable', 'image']]), 80);
        $probes = $this->bySays($planned);

        $this->assertSame(['@file' => 'pdf', 'mime' => 'application/pdf', 'kb' => 1], $planned['baselines'][0]['plan']);
        $this->assertSame('refuse', $probes['plan left out']['expect']);
        $this->assertSame(['exe', 'refuse'], [$probes['plan as a file of the wrong type (.exe)']['payload']['plan']['@file'], $probes['plan as a file of the wrong type (.exe)']['expect']]);
        $this->assertSame([101, 'refuse'], [$probes['plan of 101 KB (max:100)']['payload']['plan']['kb'], $probes['plan of 101 KB (max:100)']['expect']]);
        $this->assertSame([100, 'accept'], [$probes['plan of 100 KB (max:100)']['payload']['plan']['kb'], $probes['plan of 100 KB (max:100)']['expect']]);
        $this->assertSame('accept', $probes['photo left out']['expect']);
        $this->assertArrayHasKey('photo as a file of the wrong type (.exe)', $probes);

        $test = InputProbes::test($this->routes(), $planned, 'inputs.jsonl');
        $this->assertStringContainsString("UploadedFile::fake()->create('probe.'.\$value['@file'], \$value['kb'], \$value['mime'])", $test);
        $this->assertNotFalse(token_get_all($test, TOKEN_PARSE));
    }

    public function test_a_file_with_any_type_allowed_gets_no_wrong_type_and_an_unfakeable_one_stops(): void
    {
        $any = $this->bySays(InputProbes::plan($this->routes(), $this->rules(['notes' => ['nullable', 'file']]), 80));
        $sized = InputProbes::plan($this->routes(), $this->rules(['photo' => ['required', 'image', 'dimensions:min_width=100']]), 80);

        $this->assertArrayHasKey('notes as the wrong kind of value ("not-a-file")', $any);
        $this->assertSame([], array_filter(array_keys($any), fn (string $says) => str_contains($says, 'wrong type')));
        $this->assertSame([['route' => 0, 'field' => '', 'reason' => 'cannot_fill:photo']], $sized['coverage']);
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
