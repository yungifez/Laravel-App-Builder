<?php

namespace Tests\Unit;

use App\Features\AccessProbes;
use Tests\TestCase;

class AccessProbesTest extends TestCase
{
    /**
     * A booking that anyone signed in may add, and only the person who
     * added it may see, change or remove.
     *
     * @return array{name: string, fields: list<array{name: string, type: string, required: bool, choices: list<string>, of: string|null}>, access: array{view: string, create: string, update: string, delete: string}, label: string}
     */
    protected function booking(): array
    {
        return [
            'name' => 'Booking',
            'label' => 'booking',
            'fields' => [
                ['name' => 'user', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'User'],
                ['name' => 'starts_at', 'type' => 'datetime', 'required' => true, 'choices' => [], 'of' => null],
            ],
            'access' => ['view' => 'creator', 'create' => 'signed_in', 'update' => 'creator', 'delete' => 'creator'],
        ];
    }

    /**
     * The route list of a resource controller for bookings, and some
     * routes that are not about bookings.
     */
    protected function routes(): string
    {
        $route = fn (string $method, string $uri, ?string $name, string $action, ?string $domain = null) => compact('domain', 'method', 'uri', 'name', 'action') + ['middleware' => ['web', 'auth']];
        $controller = 'App\Http\Controllers\BookingController';

        return (string) json_encode([
            $route('GET|HEAD', 'bookings', 'bookings.index', "{$controller}@index"),
            $route('POST', 'bookings', 'bookings.store', "{$controller}@store"),
            $route('GET|HEAD', 'bookings/{booking}', 'bookings.show', "{$controller}@show"),
            $route('GET|HEAD', 'bookings/{booking}/edit', 'bookings.edit', "{$controller}@edit"),
            $route('PUT|PATCH', 'bookings/{booking}', 'bookings.update', "{$controller}@update"),
            $route('DELETE', 'bookings/{booking}', 'bookings.destroy', "{$controller}@destroy"),
            $route('GET|HEAD', 'teams/{team}/bookings/{booking}', null, "{$controller}@show"),
            $route('GET|HEAD', 'b/{booking}', null, "{$controller}@show", 'admin.example.com'),
            $route('POST', 'logout', 'logout', 'App\Http\Controllers\Auth\LogoutController@store'),
        ]);
    }

    public function test_each_route_that_works_on_a_record_is_tried_by_each_actor_its_rules_refuse(): void
    {
        $planned = AccessProbes::plan([$this->booking()], $this->routes(), 100);

        $tried = array_map(fn (array $probe) => "{$probe['actor']} {$probe['method']} {$probe['uri']} {$probe['action']}", $planned['probes']);

        $this->assertSame([
            'guest GET /bookings/{booking} view',
            'stranger GET /bookings/{booking} view',
            'guest GET /bookings/{booking}/edit update',
            'stranger GET /bookings/{booking}/edit update',
            'guest PUT /bookings/{booking} update',
            'stranger PUT /bookings/{booking} update',
            'guest PATCH /bookings/{booking} update',
            'stranger PATCH /bookings/{booking} update',
            'guest DELETE /bookings/{booking} delete',
            'stranger DELETE /bookings/{booking} delete',
            // Anyone signed in may add one, so only a visitor is refused.
            'guest POST /bookings create',
        ], $tried);
        $this->assertSame('user_id', $planned['probes'][0]['creator']);
        $this->assertSame([], $planned['unmatched']);
    }

    public function test_a_record_anyone_may_use_is_not_tried_and_a_record_without_routes_is_named(): void
    {
        $open = ['name' => 'Notice', 'fields' => [], 'access' => ['view' => 'everyone', 'create' => 'everyone', 'update' => 'everyone', 'delete' => 'everyone']];
        $unrouted = [...$this->booking(), 'name' => 'Invoice'];
        $unruled = [...$this->booking(), 'access' => null];

        $planned = AccessProbes::plan([$open, $unrouted, $unruled], $this->routes(), 100);

        $this->assertSame([], $planned['probes']);
        $this->assertSame(['Notice', 'Invoice'], $planned['unmatched']);
    }

    public function test_the_probes_are_cut_at_the_limit(): void
    {
        $this->assertCount(3, AccessProbes::plan([$this->booking()], $this->routes(), 3)['probes']);
    }

    public function test_the_written_test_sends_each_probe_and_reports_it(): void
    {
        $probes = AccessProbes::plan([$this->booking()], $this->routes(), 2)['probes'];

        $test = AccessProbes::test($probes, 'storage/logs/access/probes.jsonl');

        $this->assertStringContainsString("\$this->probe(1, 'App\\\\Models\\\\Booking', 'user_id', 'view', 'GET', '/bookings/{booking}', 'booking', NULL, 'stranger', false, null);", $test);
        $this->assertStringContainsString("base_path('storage/logs/access/probes.jsonl')", $test);
        $this->assertNotFalse(token_get_all($test, TOKEN_PARSE));
    }

    public function test_a_refused_actor_that_changed_or_saw_a_record_is_a_finding(): void
    {
        $probes = AccessProbes::plan([$this->booking()], $this->routes(), 100)['probes'];
        $observed = AccessProbes::parse(implode("\n", [
            '{"id":0,"status":302,"changed":false,"invalid":false}',
            // Another person saw someone else's booking.
            '{"id":1,"status":200,"changed":false,"invalid":false}',
            '{"id":8,"status":302,"changed":false,"invalid":false}',
            // Another person removed someone else's booking.
            '{"id":9,"status":302,"changed":true,"invalid":false}',
            // Broke, or the values were turned down: not judged.
            '{"id":4,"status":500,"changed":false,"invalid":false}',
            '{"id":5,"status":302,"changed":false,"invalid":true}',
            'not json',
        ]));

        $measured = AccessProbes::measure($probes, $observed);

        $this->assertSame(['GET /bookings/{booking}', 'DELETE /bookings/{booking}'], array_map(fn (array $finding) => "{$finding['method']} {$finding['uri']}", $measured['findings']));
        $this->assertSame(2, $measured['refused']);
        $this->assertSame(4, $measured['tried']);
        $this->assertSame(7, $measured['untried']);

        $this->assertSame(implode("\n", [
            'A signed-in person who did not add it could see a booking: GET /bookings/{booking} answered 200. The plan allows only the person who added it. Make this route check the Booking policy.',
            'A signed-in person who did not add it could remove a booking: DELETE /bookings/{booking} answered 302. The plan allows only the person who added it. Make this route check the Booking policy.',
            'Tried 4 requests as a signed-out visitor and as another signed-in person; 2 were refused, as they should be.',
            '7 could not be judged: the request broke, or the values sent were turned down first.',
        ]), AccessProbes::describe($measured, []));
    }

    public function test_a_record_the_app_had_is_tried_against_its_own_policy_only_on_routes_the_change_touched(): void
    {
        $touched = AccessProbes::fromPolicies(['Booking'], $this->routes(), ['App\Http\Controllers\BookingController'], 100);

        $this->assertCount(12, $touched, 'both actors on each of the six booking routes');
        $this->assertSame(['policy'], array_values(array_unique(array_column($touched, 'rule'))));
        $this->assertNull($touched[0]['creator']);
        $this->assertSame([], AccessProbes::fromPolicies(['Booking'], $this->routes(), ['App\Http\Controllers\RoomController'], 100));
        $this->assertSame([], AccessProbes::fromPolicies(['Booking'], $this->routes(), [], 100));
    }

    public function test_only_a_request_the_policy_refuses_can_be_a_finding(): void
    {
        $probes = array_slice(AccessProbes::fromPolicies(['Booking'], $this->routes(), ['App\Http\Controllers\BookingController'], 100), 0, 4);
        $observed = AccessProbes::parse(implode("\n", [
            // The policy refuses the visitor, and the page was refused.
            '{"id":0,"status":302,"changed":false,"invalid":false,"policy":false}',
            // The policy lets anyone signed in see it: nothing to judge.
            '{"id":1,"status":200,"changed":false,"invalid":false,"policy":true}',
            // The policy refuses, yet the edit form opened.
            '{"id":3,"status":200,"changed":false,"invalid":false,"policy":false}',
        ]));

        $measured = AccessProbes::measure($probes, $observed);

        $this->assertSame(['tried' => 2, 'refused' => 1, 'untried' => 1], array_diff_key($measured, ['findings' => true]));
        $this->assertStringStartsWith("A signed-in person who did not add it could open the form to change a booking: GET /bookings/{booking}/edit answered 200. The app's own Booking policy refuses this.", AccessProbes::describe($measured, []));
    }

    public function test_a_person_outside_a_team_tries_the_routes_of_its_records_even_under_the_team(): void
    {
        $teams = AccessProbes::teams("Booting.\n".'{"tenants":["Team"],"owned":{"MeetingRoom":{"tenant":"Team","key":"team_id"},"Bad":{"tenant":"x;y","key":"id"}}}');
        $routes = (string) json_encode([
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'teams/{team}/rooms/{meetingRoom}', 'name' => null, 'action' => 'App\Http\Controllers\RoomController@show'],
            ['domain' => null, 'method' => 'PUT', 'uri' => 'settings/teams/{team}', 'name' => null, 'action' => 'App\Http\Controllers\TeamController@update'],
            ['domain' => null, 'method' => 'DELETE', 'uri' => 'rooms/{meetingRoom}', 'name' => null, 'action' => 'App\Http\Controllers\OtherController@destroy'],
        ]);

        $this->assertSame(['tenants' => ['Team'], 'owned' => ['MeetingRoom' => ['tenant' => 'Team', 'key' => 'team_id']]], $teams);
        $this->assertNull(AccessProbes::teams('Class "Team" not found'));

        $probes = AccessProbes::forTenants((array) $teams, $routes, ['App\Http\Controllers\RoomController', 'App\Http\Controllers\TeamController'], 10);

        $this->assertSame(['PUT /settings/teams/{team}', 'GET /teams/{team}/rooms/{meetingRoom}'], array_map(fn (array $probe) => "{$probe['method']} {$probe['uri']}", $probes));
        $this->assertSame(['stranger'], array_values(array_unique(array_column($probes, 'actor'))));
        $this->assertSame(['param' => 'team', 'model' => 'Team', 'key' => 'team_id'], $probes[1]['scope']);
        $this->assertNotFalse(token_get_all(AccessProbes::test($probes, 'probes.jsonl'), TOKEN_PARSE));

        $measured = AccessProbes::measure($probes, AccessProbes::parse('{"id":1,"status":200,"changed":false,"invalid":false,"policy":null}'));
        $this->assertStringStartsWith('A signed-in person outside the team could see a meeting room: GET /teams/{team}/rooms/{meetingRoom} answered 200. Nobody outside a team may reach it or its records. Make this route check that the person belongs to the team.', AccessProbes::describe($measured, []));
    }

    public function test_the_script_that_finds_the_teams_is_plain_php(): void
    {
        $this->assertNotFalse(token_get_all(AccessProbes::introspection(), TOKEN_PARSE));
    }
}
