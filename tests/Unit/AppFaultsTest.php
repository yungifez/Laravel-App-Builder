<?php

namespace Tests\Unit;

use App\Features\AppFaults;
use App\Features\AppTraces;
use Tests\TestCase;

class AppFaultsTest extends TestCase
{
    /**
     * A patch that adds lines 3 to 5 to the order controller.
     */
    protected const PATCH = <<<'DIFF'
        diff --git a/app/Http/Controllers/OrderController.php b/app/Http/Controllers/OrderController.php
        --- a/app/Http/Controllers/OrderController.php
        +++ b/app/Http/Controllers/OrderController.php
        @@ -1,2 +1,5 @@
         <?php
         // Orders
        +$order->save();
        +Mail::send($receipt);
        +$stock->decrement('left');
        DIFF;

    protected const NEW = 'app/Http/Controllers/OrderController.php';

    protected const TEST = 'Tests\Feature\OrderTest::test_customers_order';

    /**
     * One recorded request, as the recorder writes it.
     *
     * @param  list<array<string, mixed>>  $effects
     * @param  array<string, mixed>  $extra
     */
    protected function recorded(string $method, string $route, int $status, array $effects, array $extra = []): string
    {
        return (string) json_encode(['test' => self::TEST, 'n' => 0, 'method' => $method, 'route' => $route, 'status' => $status, 'refused' => $status >= 400, 'effects' => $effects, 'blind' => [], ...$extra]);
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
     * @return array<string, mixed>
     */
    protected function mailed(?string $at, int $open = 0): array
    {
        return ['kind' => 'mail', 'what' => 'App\Mail\Receipt', 'open' => $open, 'at' => $at];
    }

    /**
     * @param  list<string>  $requests
     * @return list<array<string, mixed>>
     */
    protected function points(array $requests): array
    {
        return AppFaults::points(AppTraces::parse(implode("\n", $requests)), self::PATCH);
    }

    /**
     * Measure one place against the request its failure was caused in.
     *
     * @param  list<array<string, mixed>>  $normal  What the request did in the tests' normal run
     * @param  list<array<string, mixed>>  $failed  What it did when the failure was caused
     * @return array<string, mixed>|null
     */
    protected function measure(array $normal, int $status, array $failed, int $point = 0): ?array
    {
        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, $normal)), self::PATCH);
        $run = AppTraces::parse($this->recorded('POST', '/orders', $status, $failed, ['fault' => $points[$point]['fault']['effect']]));

        return AppFaults::measure($points, [$point => $run], self::PATCH);
    }

    public function test_it_finds_each_send_and_the_last_save_of_each_transaction_in_requests_that_ran_the_change()
    {
        $points = $this->points([
            $this->recorded('POST', '/orders', 302, [
                $this->asked('select * from "products" where "id" = ?', 'app/Models/Product.php:9'),
                ['kind' => 'begin', 'open' => 1],
                $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3', open: 1),
                $this->asked('update "products" set "left" = ? where "id" = ?', 'app/Models/Product.php:30', open: 1),
                ['kind' => 'commit', 'open' => 0],
                $this->mailed(self::NEW.':4'),
                ['kind' => 'http', 'what' => 'POST api.stripe.com', 'open' => 0, 'at' => 'app/Billing/Charge.php:12'],
                // A save outside a transaction is not undone when it fails, so it is no place.
                $this->asked('update "users" set "ordered_at" = ?', self::NEW.':5'),
            ], ['n' => 2]),
            // The same place in another request is one place.
            $this->recorded('POST', '/orders', 302, [$this->mailed(self::NEW.':4')]),
            // A request that did not run the change's code gives no place.
            $this->recorded('POST', '/teams', 302, [['kind' => 'mail', 'what' => 'App\Mail\Welcome', 'open' => 0, 'at' => 'app/Actions/CreateTeam.php:20']]),
        ]);

        // The place on the change's own line comes first.
        $this->assertSame([
            ['send', 'mail App\Mail\Receipt', self::NEW.':4', true],
            ['save', 'update products', 'app/Models/Product.php:30', false],
            ['send', 'http POST api.stripe.com', 'app/Billing/Charge.php:12', false],
        ], array_map(fn (array $point) => [$point['fails'], $point['failed'], $point['at'], $point['own']], $points));
        $this->assertSame(['test' => self::TEST, 'request' => 2, 'effect' => 5, 'kind' => 'mail'], $points[0]['fault']);
        $this->assertSame(['test' => self::TEST, 'request' => 2, 'effect' => 3, 'kind' => 'query'], $points[1]['fault']);
        $this->assertSame('test_customers_order', $points[0]['filter']);
        $this->assertSame('POST /orders', $points[0]['route']);
    }

    public function test_requests_that_cannot_be_run_again_alone_and_what_a_queued_job_does_give_no_place()
    {
        $effects = [$this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'), $this->mailed(self::NEW.':4')];

        $this->assertCount(1, $this->points([$this->recorded('POST', '/orders', 302, $effects)]));
        $this->assertSame('test_customers_order', $this->points([$this->recorded('POST', '/orders', 302, $effects, ['test' => self::TEST.' with data set "a::b"'])])[0]['filter']);

        // No test, a name a filter cannot take, no place among the test's requests, or a trace cut short.
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['test' => null])]));
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['test' => 'Tests\Feature\OrderTest::it orders (twice)'])]));
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['n' => null])]));
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['cut' => true])]));

        // In use the job runs later, on a queue: its email is not the request's.
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, [
            $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'),
            ['kind' => 'job', 'what' => 'App\Jobs\SendReceipt', 'open' => 0, 'at' => self::NEW.':4'],
            [...$this->mailed('app/Jobs/SendReceipt.php:20'), 'job' => true],
            ['kind' => 'begin', 'open' => 1, 'job' => true],
            [...$this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:21', open: 1), 'job' => true],
            ['kind' => 'commit', 'open' => 0, 'job' => true],
        ])]));
    }

    public function test_an_email_that_fails_after_the_app_saved_is_found_when_the_person_gets_an_error()
    {
        $saved = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $measured = $this->measure([$saved, $this->mailed(self::NEW.':4')], 500, [$saved, $this->mailed(self::NEW.':4')]);

        $this->assertSame(['points' => 1, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key($measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'saved_then_failed', 'route' => 'POST /orders', 'failed' => 'mail App\Mail\Receipt', 'what' => 'insert orders', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings']);
    }

    public function test_an_email_that_fails_is_no_finding_when_the_app_puts_the_save_back_or_takes_the_failure_in()
    {
        $saved = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3', open: 1);
        $normal = [['kind' => 'begin', 'open' => 1], $saved, $this->mailed(self::NEW.':4', open: 1), ['kind' => 'commit', 'open' => 0]];

        // The transaction around the email was rolled back: nothing stayed.
        $undone = $this->measure($normal, 500, [['kind' => 'begin', 'open' => 1], $saved, $this->mailed(self::NEW.':4', open: 1), ['kind' => 'rollback', 'open' => 0]]);
        // The app caught the failure and answered as usual.
        $handled = $this->measure($normal, 302, $normal);

        $this->assertSame([1, []], [$undone['run'], $undone['findings']]);
        $this->assertSame([1, []], [$handled['run'], $handled['findings']]);
    }

    public function test_a_save_that_fails_after_the_app_sent_or_saved_something_else_is_found()
    {
        $audit = $this->asked('insert into "order_attempts" ("user_id") values (?)', self::NEW.':3');
        $order = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':5', open: 1);
        $normal = [$audit, $this->mailed(self::NEW.':4'), ['kind' => 'begin', 'open' => 1], $order, ['kind' => 'commit', 'open' => 0]];

        // The second place is the save; the first is the email.
        $measured = $this->measure($normal, 500, [
            $audit,
            $this->mailed(self::NEW.':4'),
            ['kind' => 'begin', 'open' => 1],
            $order,
            ['kind' => 'rollback', 'open' => 0],
            // What the app does once it has failed is not held against it.
            $this->asked('insert into "failures" ("message") values (?)', 'app/Exceptions/Report.php:8'),
        ], point: 1);

        $this->assertSame([
            ['kind' => 'sent_then_lost', 'failed' => 'insert orders', 'what' => 'mail App\Mail\Receipt', 'at' => self::NEW.':5'],
            ['kind' => 'saved_in_part', 'failed' => 'insert orders', 'what' => 'insert order_attempts', 'at' => self::NEW.':5'],
        ], array_map(fn (array $finding) => array_intersect_key($finding, ['kind' => 1, 'failed' => 1, 'what' => 1, 'at' => 1]), $measured['findings']));
    }

    public function test_a_save_that_fails_is_no_finding_when_the_app_saves_it_again_or_carries_on()
    {
        $order = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':5', open: 1);
        $normal = [$this->mailed(self::NEW.':4'), ['kind' => 'begin', 'open' => 1], $order, ['kind' => 'commit', 'open' => 0]];

        // An app that runs the transaction again saves it after all.
        $again = $this->measure($normal, 302, [$this->mailed(self::NEW.':4'), ['kind' => 'begin', 'open' => 1], $order, ['kind' => 'rollback', 'open' => 0], ['kind' => 'begin', 'open' => 1], $order, ['kind' => 'commit', 'open' => 0]], point: 1);
        // The app caught the failure and committed: what it did then is not known.
        $carriedOn = $this->measure($normal, 302, $normal, point: 1);
        // Nothing was sent or saved before the save that failed.
        $clean = $this->measure([['kind' => 'begin', 'open' => 1], $order, ['kind' => 'commit', 'open' => 0]], 500, [['kind' => 'begin', 'open' => 1], $order, ['kind' => 'rollback', 'open' => 0]]);

        $this->assertSame([[1, []], [1, []], [1, []]], array_map(fn (array $measured) => [$measured['run'], $measured['findings']], [$again, $carriedOn, $clean]));
    }

    public function test_what_only_code_the_app_already_had_left_behind_is_counted_but_not_held_against_the_change()
    {
        $old = 'app/Actions/CreateUser.php';
        $normal = [
            // The change's line ran, so the request is used.
            $this->asked('select * from "plans" where "id" = ?', self::NEW.':3'),
            $this->asked('insert into "users" ("email") values (?)', "{$old}:12"),
            $this->mailed("{$old}:14"),
        ];

        $measured = $this->measure($normal, 500, $normal);

        $this->assertSame(['points' => 1, 'run' => 1, 'missed' => 0, 'existing' => 1, 'findings' => []], $measured);
    }

    public function test_a_failure_that_did_not_happen_is_missed_and_a_place_not_tried_is_neither()
    {
        $normal = $this->recorded('POST', '/orders', 302, [
            $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'),
            $this->mailed(self::NEW.':4'),
            ['kind' => 'http', 'what' => 'POST api.stripe.com', 'open' => 0, 'at' => self::NEW.':5'],
        ]);
        $points = AppFaults::points(AppTraces::parse($normal), self::PATCH);

        // The first place's run recorded the request, but with no failure in it; the second was not tried.
        $measured = AppFaults::measure($points, [0 => AppTraces::parse($normal)], self::PATCH);

        $this->assertSame(['points' => 2, 'run' => 0, 'missed' => 1, 'existing' => 0, 'findings' => []], $measured);
        $this->assertSame(1, AppFaults::measure($points, [0 => []], self::PATCH)['missed']);
        $this->assertNull(AppFaults::measure([], [], self::PATCH));
    }
}
