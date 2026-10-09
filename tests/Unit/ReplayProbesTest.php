<?php

namespace Tests\Unit;

use App\Features\ReplayProbes;
use Tests\TestCase;

class ReplayProbesTest extends TestCase
{
    /**
     * The routes that add a booking and a room, and one that adds nothing
     * the change works on.
     */
    protected function routes(): string
    {
        $route = fn (string $method, string $uri, ?string $name, string $action) => ['domain' => null, 'method' => $method, 'uri' => $uri, 'name' => $name, 'action' => $action, 'middleware' => ['web', 'auth']];

        return (string) json_encode([
            $route('POST', 'bookings', 'bookings.store', 'App\Http\Controllers\BookingController@store'),
            $route('POST', 'rooms', 'rooms.store', 'App\Http\Controllers\RoomController@store'),
            $route('POST', 'logout', 'logout', 'App\Http\Controllers\Auth\LogoutController@store'),
        ]);
    }

    public function test_a_planned_record_and_a_model_of_a_touched_controller_are_sent_twice(): void
    {
        $booking = ['name' => 'Booking', 'fields' => [['name' => 'user', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'User']], 'access' => null];

        $probes = ReplayProbes::plan([$booking], ['Booking', 'Room', 'Team'], $this->routes(), ['App\Http\Controllers\RoomController'], 10);

        $this->assertSame([
            ['record' => 'Booking', 'noun' => 'booking', 'creator' => 'user_id', 'uri' => '/bookings'],
            ['record' => 'Room', 'noun' => 'room', 'creator' => null, 'uri' => '/rooms'],
        ], $probes);
        $this->assertSame([], ReplayProbes::plan([], ['Room'], $this->routes(), [], 10), 'a room the change did not touch is left alone');
        $this->assertCount(1, ReplayProbes::plan([$booking], ['Room'], $this->routes(), ['App\Http\Controllers\RoomController'], 1));
    }

    public function test_the_written_test_is_plain_php_that_sends_each_form_twice(): void
    {
        $test = ReplayProbes::test([['record' => 'Booking', 'noun' => 'booking', 'creator' => 'user_id', 'uri' => '/bookings']], 'replay.jsonl');

        $this->assertNotFalse(token_get_all($test, TOKEN_PARSE));
        $this->assertStringContainsString("\$this->replay(0, 'App\\\\Models\\\\Booking', 'user_id', '/bookings');", $test);
        $this->assertStringContainsString("base_path('replay.jsonl')", $test);
    }

    public function test_only_a_second_send_the_database_refused_as_a_duplicate_is_a_finding(): void
    {
        $probe = fn (string $record) => ['record' => $record, 'noun' => strtolower($record), 'creator' => null, 'uri' => '/'.strtolower($record).'s'];
        $probes = [$probe('Booking'), $probe('Room'), $probe('Note'), $probe('Tag')];

        $measured = ReplayProbes::measure($probes, ReplayProbes::parse(implode("\n", [
            '{"id":0,"first":201,"added":true,"second":500,"duplicate":"bookings.room_id, bookings.starts_at"}',
            // Turned down with a message: as it should be.
            '{"id":1,"first":302,"added":true,"second":302,"duplicate":null}',
            // The first send was turned down: nothing to judge.
            '{"id":2,"first":302,"added":false,"second":0,"duplicate":null}',
            // Broke for another reason: not judged as a duplicate.
            '{"id":3,"first":302,"added":true,"second":500,"duplicate":null}',
            'not json',
        ])));

        $this->assertSame(['Booking'], array_column($measured['findings'], 'record'));
        $this->assertSame(3, $measured['tried']);
        $this->assertSame(implode("\n", [
            'Sending the form that adds a booking twice broke the page: the second POST /bookings answered 500, because the database already had a booking with that value (bookings.room_id, bookings.starts_at). Check it in the form request with a unique rule, so the person gets a message instead.',
            'Sent 3 forms twice as a signed-in person.',
        ]), ReplayProbes::describe($measured));
    }
}
