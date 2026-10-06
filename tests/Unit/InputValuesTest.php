<?php

namespace Tests\Unit;

use App\Features\InputValues;
use Tests\TestCase;

class InputValuesTest extends TestCase
{
    public function test_a_valid_value_keeps_to_each_rule(): void
    {
        $this->assertSame(['255'], InputValues::rule(['required', 'max:255'], 'max'));
        $this->assertSame([], InputValues::rule(['required', 'max:255'], 'required'));
        $this->assertNull(InputValues::rule(['required'], 'min'));

        $this->assertLessThanOrEqual(5, strlen(InputValues::valid(['required', 'string', 'max:5'], 'title')));
        $this->assertSame(30, strlen(InputValues::valid(['required', 'string', 'min:30'], 'title')));
        $this->assertSame(8, strlen(InputValues::valid(['string', 'size:8'], 'code')));
        $this->assertSame('PROBE', InputValues::valid(['string', 'alpha', 'uppercase'], 'code'));
        $this->assertStringStartsWith('SKU-', InputValues::valid(['string', 'starts_with:SKU-'], 'sku'));
        $this->assertSame(3, InputValues::valid(['required', 'integer', 'min:3', 'max:8'], 'guests'));
        $this->assertSame(1, InputValues::valid(['numeric'], 'price'));
        $this->assertSame('single', InputValues::valid(['required', 'in:single,double'], 'kind'));
        $this->assertSame(['@exists' => 'teams', 'column' => 'id'], InputValues::valid(['required', 'exists:teams,id'], 'team_id'));
        $this->assertSame(['@exists' => 'rooms', 'column' => 'id'], InputValues::valid(['exists:tenant.rooms'], 'room_id'), 'a connection name is left off');
        $this->assertSame(['@exists' => 'rooms', 'column' => 'code'], InputValues::valid(['exists:rooms'], 'code'));
        $this->assertSame(['@date' => 30, 'format' => 'd/m/Y'], InputValues::valid(['required', 'date_format:d/m/Y'], 'starts_on'));
        $this->assertSame('11111', InputValues::valid(['digits:5'], 'pin'));
        $this->assertTrue(InputValues::valid(['accepted'], 'terms'));
    }

    public function test_a_unique_email_differs_per_field_and_a_plain_one_does_not(): void
    {
        $this->assertSame('probe@example.com', InputValues::valid(['required', 'email'], 'email'));
        $this->assertNotSame(InputValues::valid(['email', 'unique:users'], 'email'), InputValues::valid(['email', 'unique:users'], 'backup_email'));
        $this->assertMatchesRegularExpression('/^probe-\w{6}@example\.com$/', InputValues::valid(['email', 'unique:users'], 'email'));
    }

    public function test_a_wrong_value_is_of_another_kind_or_outside_the_choices(): void
    {
        $this->assertSame('not-a-number', InputValues::wrongKind(['integer']));
        $this->assertSame('not-a-date', InputValues::wrongKind(['date_format:Y-m-d']));
        $this->assertSame('not-an-email', InputValues::wrongKind(['email']));
        $this->assertSame(['not', 'text'], InputValues::wrongKind(['string', 'max:20']));
        $this->assertSame(987_654_321, InputValues::outsideChoices(['in:1,2,3']));
        $this->assertSame('not-a-choice', InputValues::outsideChoices(['in:single,double']));
        $this->assertSame(2_000_000_001, InputValues::missingRow(['exists:teams,id']));
        $this->assertSame('no-such-value', InputValues::missingRow(['exists:teams,slug']));
    }

    public function test_rules_the_probes_cannot_meet_give_no_value(): void
    {
        $this->assertNull(InputValues::valid(['required', 'regex:/^[A-Z]{3}$/'], 'code'));
        $this->assertNull(InputValues::valid(['required', 'image'], 'photo'));
        $this->assertNull(InputValues::valid(['array'], 'tags'));
        $this->assertNull(InputValues::wrongKind(['required']), 'no kind to get wrong');
        $this->assertSame('file', InputValues::kind(['mimes:pdf']));
    }
}
