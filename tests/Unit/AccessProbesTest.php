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

        $this->assertStringContainsString("\$this->probe(1, 'App\\\\Models\\\\Booking', 'user_id', 'view', 'GET', '/bookings/{booking}', 'booking', NULL, 'stranger');", $test);
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
            'Tried 4 requests as a signed-out visitor and as another signed-in person; 2 were refused as planned.',
            '7 could not be judged: the request broke, or the values sent were turned down first.',
        ]), AccessProbes::describe($measured, []));
    }
}
