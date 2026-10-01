<?php

namespace Tests\Unit;

use App\Features\AppTraces;
use Tests\TestCase;

class AppTracesTest extends TestCase
{
    /**
     * A patch that adds lines 3 to 5 to the invitation controller.
     */
    protected const PATCH = <<<'DIFF'
        diff --git a/app/Http/Controllers/InvitationController.php b/app/Http/Controllers/InvitationController.php
        --- a/app/Http/Controllers/InvitationController.php
        +++ b/app/Http/Controllers/InvitationController.php
        @@ -1,2 +1,5 @@
         <?php
         // Invitations
        +$invitation->save();
        +Mail::send($invitation);
        +$team->members->each->role;
        DIFF;

    protected const NEW = 'app/Http/Controllers/InvitationController.php';

    /**
     * One recorded request, as the recorder writes it.
     *
     * @param  list<array<string, mixed>>  $effects
     * @param  array<string, mixed>  $extra
     */
    protected function recorded(string $method, string $route, int $status, array $effects, array $extra = []): string
    {
        return (string) json_encode(['test' => 'Tests\Feature\InviteTest::test_owners_invite', 'method' => $method, 'route' => $route, 'status' => $status, 'refused' => $status >= 400, 'effects' => $effects, 'blind' => [], ...$extra]);
    }

    /**
     * A query of a request, from a line of the app's code.
     *
     * @return array<string, mixed>
     */
    protected function asked(string $sql, ?string $at, int $open = 0): array
    {
        return ['kind' => 'query', 'sql' => $sql, 'open' => $open, 'at' => $at];
    }

    /**
     * @param  list<string>  $requests
     * @param  list<string>  $addedRoutes
     * @return array<string, mixed>|null
     */
    protected function measure(array $requests, array $addedRoutes = []): ?array
    {
        return AppTraces::measure(AppTraces::parse(implode("\n", $requests)), self::PATCH, $addedRoutes);
    }

    public function test_it_reads_one_request_per_line_and_leaves_out_what_is_not_one()
    {
        $requests = AppTraces::parse(implode("\n", [
            $this->recorded('GET', '/teams', 200, [$this->asked('select * from "teams"', 'app/Models/Team.php:9'), ['kind' => 'begin', 'open' => 1], 'not an effect']),
            'not json',
            '{"effects": []}',
        ]));

        $this->assertCount(1, $requests);
        $this->assertSame('/teams', $requests[0]['route']);
        $this->assertSame([
            ['kind' => 'query', 'open' => 0, 'sql' => 'select * from "teams"', 'at' => 'app/Models/Team.php:9'],
            ['kind' => 'begin', 'open' => 1],
        ], $requests[0]['effects']);
        $this->assertFalse($requests[0]['cut']);
        $this->assertNull(AppTraces::measure([], self::PATCH));
    }

    public function test_a_request_that_only_reads_but_saves_from_a_new_line_is_found()
    {
        $measured = $this->measure([
            $this->recorded('GET', '/invitations/{invitation}', 200, [
                $this->asked('select * from "invitations" where "id" = ? limit 1', self::NEW.':3'),
                $this->asked('update "invitations" set "seen_at" = ? where "id" = ?', self::NEW.':3'),
                // The framework's own writes and the app's old code are not the change's.
                $this->asked('update "sessions" set "payload" = ?', null),
                $this->asked('update "teams" set "opened" = ?', 'app/Models/Team.php:40'),
            ]),
            // The same thing seen in another request of the same route is said once.
            $this->recorded('GET', '/invitations/{invitation}', 200, [$this->asked('update "invitations" set "seen_at" = ? where "id" = ?', self::NEW.':3')]),
            $this->recorded('POST', '/invitations', 302, [$this->asked('insert into "invitations" ("email") values (?)', self::NEW.':3')]),
        ]);

        $this->assertSame(3, $measured['requests']);
        $this->assertSame(3, $measured['reached']);
        $this->assertSame(1, $measured['existing']);
        $this->assertSame([
            ['kind' => 'saved_on_read', 'route' => 'GET /invitations/{invitation}', 'what' => 'update invitations', 'at' => self::NEW.':3', 'test' => 'Tests\Feature\InviteTest::test_owners_invite'],
        ], $measured['findings']);
    }

    public function test_a_refused_request_that_keeps_what_it_saved_is_found_but_not_one_that_puts_it_back()
    {
        $write = $this->asked('insert into "invitations" ("email") values (?)', self::NEW.':3', open: 1);
        $measured = $this->measure([
            // Saved, then refused: the write stays.
            $this->recorded('POST', '/invitations', 403, [$this->asked('insert into "invitations" ("email") values (?)', self::NEW.':3')]),
            // Sent back with validation errors after saving.
            $this->recorded('PUT', '/invitations/{invitation}', 302, [$this->asked('delete from "invitations" where "id" = ?', self::NEW.':3')], ['refused' => true]),
            // Rolled back: nothing stays. An inner transaction that commits inside one rolled back stays out too.
            $this->recorded('POST', '/teams', 500, [['kind' => 'begin', 'open' => 1], $write, ['kind' => 'begin', 'open' => 2], $write, ['kind' => 'commit', 'open' => 1], ['kind' => 'rollback', 'open' => 0]]),
            // A trace cut short does not say what was put back.
            $this->recorded('POST', '/teams', 500, [$write], ['cut' => true]),
        ]);

        $this->assertSame([
            ['kind' => 'kept_after_refusal', 'route' => 'POST /invitations', 'what' => 'insert invitations'],
            ['kind' => 'kept_after_refusal', 'route' => 'PUT /invitations/{invitation}', 'what' => 'delete invitations'],
        ], array_map(fn (array $finding) => array_intersect_key($finding, ['kind' => 1, 'route' => 1, 'what' => 1]), $measured['findings']));
    }

    public function test_what_is_sent_while_a_transaction_is_open_is_found()
    {
        $measured = $this->measure([
            $this->recorded('POST', '/invitations', 302, [
                ['kind' => 'begin', 'open' => 1],
                $this->asked('insert into "invitations" ("email") values (?)', self::NEW.':3', open: 1),
                ['kind' => 'mail', 'what' => 'App\Mail\TeamInvitation', 'open' => 1, 'at' => self::NEW.':4'],
                // The framework's own sending is not the change's.
                ['kind' => 'notification', 'what' => 'Illuminate\Auth\Notifications\VerifyEmail', 'open' => 1, 'at' => null],
                ['kind' => 'commit', 'open' => 0],
                // After the commit is the right time.
                ['kind' => 'job', 'what' => 'App\Jobs\SyncSeats', 'open' => 0, 'at' => self::NEW.':4'],
            ]),
            // A test that fakes mail hides what a request with a transaction sends.
            $this->recorded('POST', '/invitations', 302, [['kind' => 'begin', 'open' => 1], $this->asked('insert into "invitations" ("email") values (?)', self::NEW.':3', open: 1), ['kind' => 'commit', 'open' => 0]], ['blind' => ['mail']]),
            // With no transaction, nothing can be sent inside one.
            $this->recorded('POST', '/invitations', 302, [$this->asked('insert into "invitations" ("email") values (?)', self::NEW.':3')], ['blind' => ['mail']]),
            // A request that did not run the change's code says nothing about it.
            $this->recorded('POST', '/teams', 302, [['kind' => 'begin', 'open' => 1], ['kind' => 'commit', 'open' => 0]], ['blind' => ['mail']]),
        ]);

        $this->assertSame([
            ['kind' => 'sent_before_saved', 'route' => 'POST /invitations', 'what' => 'mail App\Mail\TeamInvitation', 'at' => self::NEW.':4', 'test' => 'Tests\Feature\InviteTest::test_owners_invite'],
        ], $measured['findings']);
        $this->assertSame(1, $measured['unseen']);
        $this->assertSame(0, $measured['existing']);
        $this->assertSame($measured['findings'], AppTraces::findings($measured, AppTraces::SENT_BEFORE_SAVED));
        $this->assertSame([], AppTraces::findings($measured, AppTraces::SAVED_ON_READ));
    }

    public function test_everything_the_apps_code_does_on_a_route_the_change_added_is_the_changes()
    {
        $requests = [$this->recorded('GET', '/reports/{report}', 200, [
            $this->asked('update "reports" set "views" = "views" + 1', 'app/Models/Report.php:30'),
            $this->asked('update "sessions" set "payload" = ?', null),
        ])];

        $this->assertSame([], $this->measure($requests)['findings']);
        $this->assertSame(0, $this->measure($requests)['reached']);

        $onNewRoute = $this->measure($requests, ['GET /reports/{report}']);
        $this->assertSame(['update reports'], array_column($onNewRoute['findings'], 'what'));
        $this->assertSame(1, $onNewRoute['reached']);
    }

    public function test_a_lookup_one_request_repeats_from_a_new_line_joins_the_shortcuts()
    {
        $lookup = $this->asked('select * from "roles" where "member_id" = ? limit 1', self::NEW.':5');
        $measured = $this->measure([
            $this->recorded('GET', '/teams/{team}', 200, [$lookup, $lookup, $lookup, $lookup]),
            $this->recorded('GET', '/teams', 200, [$lookup, $lookup, $lookup]),
            // Twice is not a loop, and the app's old code is not the change's.
            $this->recorded('GET', '/invitations', 200, [
                $this->asked('select * from "users" where "id" = ? limit 1', self::NEW.':3'),
                $this->asked('select * from "users" where "id" = ? limit 1', self::NEW.':3'),
                ...array_fill(0, 6, $this->asked('select * from "teams" where "id" = ? limit 1', 'app/Models/Team.php:40')),
            ]),
        ]);

        $this->assertSame([['path' => self::NEW, 'line' => 5, 'count' => 4, 'route' => 'GET /teams/{team}']], $measured['repeats']);
        $this->assertSame([], $measured['findings']);

        // Each line is kept once, beside what reading the code found.
        $this->assertSame(
            [['rule' => 'SL107', 'path' => self::NEW, 'line' => 3], ['rule' => 'SL204', 'path' => self::NEW, 'line' => 5]],
            AppTraces::withRepeats([['rule' => 'SL107', 'path' => self::NEW, 'line' => 3]], $measured['repeats']),
        );
        $this->assertSame([['rule' => 'SL203', 'path' => self::NEW, 'line' => 5]], AppTraces::withRepeats([['rule' => 'SL203', 'path' => self::NEW, 'line' => 5]], $measured['repeats']));
        $this->assertSame([['rule' => 'SL204', 'path' => self::NEW, 'line' => 5]], AppTraces::withRepeats(null, $measured['repeats']));
        $this->assertNull(AppTraces::withRepeats(null, []));
    }
}
