<?php

namespace Tests\Unit;

use App\Features\AppRoutes;
use Tests\TestCase;

class AppRoutesTest extends TestCase
{
    /**
     * The framework's route list with the given routes.
     *
     * @param  list<array{string, string, list<string>}>  $routes  Method, address and middleware
     */
    protected function listing(array $routes): string
    {
        return (string) json_encode(array_map(fn (array $route) => [
            'domain' => null, 'method' => $route[0], 'uri' => $route[1], 'name' => null, 'action' => 'Closure', 'middleware' => $route[2],
        ], $routes));
    }

    public function test_it_reads_each_route_with_its_middleware_by_method_and_address()
    {
        $routes = AppRoutes::parse($this->listing([
            ['GET|HEAD', 'teams', ['web', 'auth']],
            ['PUT|PATCH', 'teams/{team}', ['web', 'auth', 'can:update,team']],
            ['GET|HEAD', '/', ['web']],
        ]));

        $this->assertSame([
            'GET /' => ['web'],
            'GET /teams' => ['web', 'auth'],
            'PATCH /teams/{team}' => ['web', 'auth', 'can:update,team'],
            'PUT /teams/{team}' => ['web', 'auth', 'can:update,team'],
        ], $routes);
    }

    public function test_output_that_is_not_a_route_list_reads_as_unknown()
    {
        $this->assertNull(AppRoutes::parse('Your application has no routes.'));
        $this->assertNull(AppRoutes::parse('{"error": "boom"}'));
        $this->assertNull(AppRoutes::parse('[{"uri": "teams"}]'));
        $this->assertSame([], AppRoutes::parse('[]'));
    }

    public function test_it_names_the_routes_a_change_added_removed_and_changed()
    {
        $before = ['GET /teams' => ['web', 'auth'], 'GET /old' => ['web'], 'DELETE /teams/{team}' => ['web', 'auth', 'verified']];
        $after = ['GET /teams' => ['web', 'auth'], 'POST /invitations' => ['web', 'auth'], 'DELETE /teams/{team}' => ['web', 'throttle:6,1']];

        $this->assertSame([
            'added' => [['route' => 'POST /invitations', 'middleware' => ['web', 'auth']]],
            'removed' => ['GET /old'],
            'changed' => [['route' => 'DELETE /teams/{team}', 'lost' => ['auth', 'verified'], 'gained' => ['throttle:6,1']]],
        ], AppRoutes::changes($before, $after));

        $this->assertNull(AppRoutes::changes($before, $before));
    }

    public function test_a_route_that_lost_a_check_on_who_may_use_it_is_found()
    {
        $changes = AppRoutes::changes(
            [
                'GET /teams' => ['web', 'auth:sanctum', 'throttle:6,1'],
                'GET /billing' => ['web', 'Illuminate\Auth\Middleware\Authenticate', 'App\Http\Middleware\Locale'],
                'GET /share/{team}' => ['web', 'signed'],
                'GET /about' => ['web', 'cache.headers:public'],
            ],
            [
                'GET /teams' => ['web'],
                'GET /billing' => ['web'],
                'GET /share/{team}' => ['web', 'signed'],
                'GET /about' => ['web'],
            ],
        );

        // Only Laravel's own checks count; an app's own middleware is not guessed at.
        $this->assertSame([
            ['route' => 'GET /teams', 'lost' => ['auth:sanctum']],
            ['route' => 'GET /billing', 'lost' => ['Illuminate\Auth\Middleware\Authenticate']],
        ], AppRoutes::opened($changes));
        $this->assertSame([], AppRoutes::opened(null));
        $this->assertSame('/teams', AppRoutes::address('GET /teams'));
        // Livewire's own address means nothing to the owner; its component does.
        $this->assertSame('the send receipt part of a page', AppRoutes::address('POST /livewire-3f2a/update#send-receipt@send'));
        $this->assertSame('the order list part of a page', AppRoutes::address('GET /livewire-unit-test-endpoint#orders.order-list'));
        $this->assertSame('the cart part of a page', AppRoutes::address('POST /livewire-3f2a/update#pages::cart@add'));
        $this->assertSame('the one part of a page', AppRoutes::address('POST /livewire-3f2a/update#one+two@save+three+more'));
        $this->assertSame('the send receipt part of a page', AppRoutes::address('GET /livewire-unit-test-endpoint#App\\Livewire\\SendReceipt'));
        $this->assertSame('a part of a page', AppRoutes::address('GET /livewire-unit-test-endpoint'));
        // Work the app does on its own has no address: it is said by its name.
        $this->assertSame('the work “reminders send”', AppRoutes::address('ARTISAN reminders:send'));
        $this->assertSame('the work “send late reminder”', AppRoutes::address('JOB App\\Jobs\\SendLateReminder'));
        $this->assertSame(['orders prune old', 'send receipt', null], [AppRoutes::work('ARTISAN orders:prune-old'), AppRoutes::work('JOB App\\Listeners\\SendReceipt'), AppRoutes::work('POST /teams')]);
    }

    public function test_a_new_route_that_changes_something_with_no_check_on_who_may_use_it_is_found()
    {
        $changes = AppRoutes::changes([], [
            'GET /pricing' => ['web'],
            'POST /contact' => ['web', 'throttle:6,1'],
            'POST /teams' => ['web', 'auth', 'verified'],
            'DELETE /teams/{team}' => ['web', 'can:delete,team'],
        ]);

        $this->assertSame(['POST /contact'], AppRoutes::unguarded($changes));
    }

    public function test_open_and_opened_routes_are_findings_until_the_owner_keeps_them()
    {
        $changes = AppRoutes::changes(
            ['GET /teams/{team}' => ['web', 'auth']],
            [
                'GET /teams/{team}' => ['web'],
                'POST /contact' => ['web'],
                'POST /teams' => ['web', 'auth'],
            ],
        );
        $findings = AppRoutes::findings($changes);

        $this->assertSame([
            ['kind' => AppRoutes::OPEN_TO_ANYONE, 'route' => 'POST /contact'],
            ['kind' => AppRoutes::NO_LONGER_CHECKED, 'route' => 'GET /teams/{team}'],
        ], $findings);
        $this->assertSame([$findings[1]], AppRoutes::findings($changes, [AppRoutes::identity($findings[0])]));
        $this->assertStringContainsString('ask the owner to keep it', AppRoutes::finding($findings[0]));
        $this->assertStringContainsString('Put the check back', AppRoutes::finding($findings[1]));
        $this->assertSame([], AppRoutes::findings(null));
    }

    public function test_a_route_the_plan_lets_everyone_use_is_the_request_not_a_finding()
    {
        $field = ['name' => 'dog_name', 'type' => 'string', 'required' => true, 'choices' => [], 'of' => null];
        $records = [
            ['name' => 'Booking', 'fields' => [$field], 'access' => ['view' => 'signed_in', 'create' => 'everyone', 'update' => 'signed_in', 'delete' => 'signed_in']],
            ['name' => 'GuestNote', 'fields' => [$field], 'access' => ['view' => 'everyone', 'create' => 'everyone', 'update' => 'everyone', 'delete' => 'everyone']],
            // No access yet: nothing is known, so nothing is let through.
            ['name' => 'Kennel', 'fields' => [$field], 'access' => null],
        ];

        $this->assertSame(
            ['POST /bookings', 'POST /guest-notes', 'PUT /guest-notes/{}', 'PATCH /guest-notes/{}', 'DELETE /guest-notes/{}'],
            AppRoutes::planned($records),
        );

        $changes = AppRoutes::changes([], [
            // Base: anyone may book, as the plan says.
            'POST /bookings' => ['web', 'throttle:20,1'],
            // Alternate: changing a record anyone may change, by its own key.
            'PATCH /guest-notes/{guest_note}' => ['web'],
            // Exception: the plan keeps changing a booking to signed-in
            // people, and a route nobody planned stays a finding.
            'PUT /bookings/{booking}' => ['web'],
            'POST /kennels' => ['web'],
            'POST /contact' => ['web'],
        ], AppRoutes::planned($records));

        $this->assertSame(['PUT /bookings/{booking}', 'POST /kennels', 'POST /contact'], AppRoutes::unguarded($changes));
        $this->assertSame(['PUT /bookings/{booking}', 'POST /kennels', 'POST /contact'], array_column(AppRoutes::findings($changes), 'route'));
    }
}
