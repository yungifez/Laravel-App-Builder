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
     * A file the app wrote to a disk or deleted from it, from a line of
     * the app's code.
     *
     * @return array<string, mixed>
     */
    protected function stored(?string $at, string $what = 'write', int $open = 0): array
    {
        return ['kind' => 'file', 'what' => $what, 'open' => $open, 'at' => $at];
    }

    /**
     * A call to an outside service, from a line of the app's code. A
     * direct call is one that line made itself.
     *
     * @return array<string, mixed>
     */
    protected function called(?string $at, string $method = 'POST', bool $keyed = false, bool $direct = false): array
    {
        return ['kind' => 'http', 'what' => "{$method} pay.example", 'open' => 0, 'at' => $at, ...($keyed ? ['keyed' => true] : []), ...($direct ? ['direct' => true] : [])];
    }

    /**
     * A job the sync queue ran, from the line of the app's code that queued it.
     *
     * @return array<string, mixed>
     */
    protected function job(?string $at): array
    {
        return ['kind' => 'job', 'what' => 'App\Jobs\SendReceipt', 'open' => 0, 'at' => $at];
    }

    /**
     * Mark a thing as done by a job the sync queue ran.
     *
     * @param  array<string, mixed>  $effect
     * @return array<string, mixed>
     */
    protected function done(array $effect): array
    {
        return [...$effect, 'job' => true];
    }

    /**
     * Mark a thing as done on the way through a listener.
     *
     * @param  array<string, mixed>  $effect
     * @return array<string, mixed>
     */
    protected function heard(array $effect, string $listener): array
    {
        return [...$effect, 'phase' => 'listener', 'frames' => ["App\\Listeners\\{$listener}::handle"]];
    }

    /**
     * An event the request dispatched, with two listeners Laravel found.
     *
     * @return array<string, mixed>
     */
    protected function dispatched(?string $at): array
    {
        return ['events' => [['what' => 'App\Events\OrderPlaced', 'at' => $at, 'listeners' => ['App\Listeners\MakeInvoice::handle', 'App\Listeners\SendReceipt::handle']]]];
    }

    /**
     * Measure an event against the request its listeners ran in the
     * reverse order in.
     *
     * @param  list<array<string, mixed>>  $normal  What the request did in the tests' normal run
     * @param  list<array<string, mixed>>  $turned  What it did in the reverse order
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    protected function reordered(array $normal, int $status, array $turned, ?string $at = self::NEW.':4', array $extra = []): ?array
    {
        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, $normal, $this->dispatched($at))), self::PATCH);
        $point = (int) array_search('reorder', array_column($points, 'fails'), true);

        return AppFaults::measure($points, [$point => AppTraces::parse($this->recorded('POST', '/orders', $status, $turned, ['fault' => 0, ...$extra]))], self::PATCH);
    }

    /**
     * Measure a job against the request it was held back in until the
     * response was made.
     *
     * @param  list<array<string, mixed>>  $normal  What the request did in the tests' normal run
     * @param  list<array<string, mixed>>  $held  What it did when the job ran after the response
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    protected function heldBack(array $normal, int $status, array $held, array $extra = []): ?array
    {
        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, $normal)), self::PATCH);
        $point = (int) array_search('later', array_column($points, 'fails'), true);

        return AppFaults::measure($points, [$point => AppTraces::parse($this->recorded('POST', '/orders', $status, $held, ['fault' => 0, ...$extra]))], self::PATCH);
    }

    /**
     * Measure an outside call against the request it was answered with a
     * server error in.
     *
     * @param  list<array<string, mixed>>  $normal  What the request did in the tests' normal run
     * @param  list<array<string, mixed>>  $answered  What it did when the call was answered with an error
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    protected function answered(array $normal, int $status, array $answered, array $extra = []): ?array
    {
        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, $normal)), self::PATCH);
        $point = (int) array_search('answer', array_column($points, 'fails'), true);

        return AppFaults::measure($points, [$point => AppTraces::parse($this->recorded('POST', '/orders', $status, $answered, ['fault' => $points[$point]['fault']['effect'], ...$extra]))], self::PATCH);
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
     * @param  array<string, mixed>  $extra  What else the recorder said of the request the failure was caused in
     * @param  array<string, mixed>  $was  What else it said of the request in the tests' normal run
     * @return array<string, mixed>|null
     */
    protected function measure(array $normal, int $status, array $failed, int $point = 0, array $extra = [], array $was = []): ?array
    {
        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, $normal, $was)), self::PATCH);
        $run = AppTraces::parse($this->recorded('POST', '/orders', $status, $failed, ['fault' => $points[$point]['fault']['effect'], ...$extra]));

        return AppFaults::measure($points, [$point => $run], self::PATCH);
    }

    public function test_it_finds_each_send_and_the_last_save_in_and_outside_a_transaction_in_requests_that_ran_the_change()
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
                // A save outside a transaction, after the request saved and sent: a save in steps.
                $this->asked('update "users" set "ordered_at" = ?', self::NEW.':5'),
            ], ['n' => 2]),
            // The same place in another request is one place.
            $this->recorded('POST', '/orders', 302, [$this->mailed(self::NEW.':4')]),
            // A request that did not run the change's code gives no place.
            $this->recorded('POST', '/teams', 302, [['kind' => 'mail', 'what' => 'App\Mail\Welcome', 'open' => 0, 'at' => 'app/Actions/CreateTeam.php:20']]),
        ]);

        // The places on the change's own lines come first. Among places
        // that are alike, what cannot be taken back comes before a save.
        $this->assertSame([
            ['send', 'mail App\Mail\Receipt', self::NEW.':4', true],
            ['save', 'update users', self::NEW.':5', true],
            ['send', 'http POST api.stripe.com', 'app/Billing/Charge.php:12', false],
            ['save', 'update products', 'app/Models/Product.php:30', false],
        ], array_map(fn (array $point) => [$point['fails'], $point['failed'], $point['at'], $point['own']], $points));
        $this->assertSame(['test' => self::TEST, 'request' => 2, 'effect' => 5, 'kind' => 'mail'], $points[0]['fault']);
        $this->assertSame(['test' => self::TEST, 'request' => 2, 'effect' => 7, 'kind' => 'query'], $points[1]['fault']);
        $this->assertSame(['test' => self::TEST, 'request' => 2, 'effect' => 3, 'kind' => 'query'], $points[3]['fault']);
        $this->assertSame('test_customers_order', $points[0]['filter']);
        $this->assertSame('POST /orders', $points[0]['route']);
    }

    public function test_a_send_or_a_save_takes_the_first_test_where_the_apps_log_can_be_seen()
    {
        $other = fn (string $test, array $extra = []) => ['test' => "Tests\\Feature\\OrderTest::{$test}", ...$extra];
        $order = fn (array $extra) => $this->recorded('POST', '/orders', 302, [$this->mailed(self::NEW.':4'), $this->job(self::NEW.':5'), $this->done($this->mailed('app/Jobs/SendReceipt.php:9'))], $extra);
        $points = $this->points([
            $order($other('test_first', ['dark' => true])),
            $order($other('test_second', ['dark' => true])),
            $order($other('test_third')),
            $order($other('test_fourth')),
            $this->recorded('POST', '/refunds', 302, [$this->mailed(self::NEW.':4')], $other('test_fifth', ['dark' => true])),
        ]);

        $this->assertSame([
            // The email keeps its place in the order, and is tried where a hidden failure can be found.
            ['send', 'POST /orders', 'test_third'],
            // No test sees the log: the first one is as good as the others.
            ['send', 'POST /refunds', 'test_fifth'],
            // For a job, the log says nothing: the first test stays.
            ['again', 'POST /orders', 'test_first'],
            ['later', 'POST /orders', 'test_first'],
            // The email of the job is tried last, and where a hidden failure can be found too.
            ['send', 'POST /orders', 'test_third'],
        ], array_map(fn (array $point) => [$point['fails'], $point['route'], $point['filter']], $points));
        $this->assertSame('Tests\\Feature\\OrderTest::test_third', $points[0]['fault']['test']);
    }

    public function test_places_another_engine_suspects_are_tried_before_the_rest_and_after_the_changes_own()
    {
        $old = 'app/Actions';
        $requests = [
            $this->recorded('POST', '/orders', 302, [
                $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'),
                $this->asked('update "products" set "left" = ?', "{$old}/TakeStock.php:9"),
                ['kind' => 'mail', 'what' => 'App\Mail\Told', 'open' => 0, 'at' => "{$old}/TellOwner.php:7"],
                ['kind' => 'http', 'what' => 'POST api.stripe.com', 'open' => 0, 'at' => "{$old}/Charge.php:12"],
                $this->job("{$old}/PlaceOrder.php:20"),
                $this->done($this->mailed('app/Jobs/SendReceipt.php:21')),
                $this->mailed(self::NEW.':4'),
            ]),
            $this->recorded('POST', '/teams', 302, [
                $this->asked('select * from "plans"', self::NEW.':3'),
                ['kind' => 'mail', 'what' => 'App\Mail\Welcome', 'open' => 0, 'at' => "{$old}/CreateTeam.php:20"],
            ]),
        ];
        $order = fn (array $suspected) => array_column(AppFaults::points(AppTraces::parse(implode("\n", $requests)), self::PATCH, $suspected), 'failed');

        // With nothing suspected: the change's own, then each send, then the job, then the save,
        // and last the email the job sends.
        // The job is there twice. The change wrote what comes after it, so holding it back
        // until the response is the change's place. Running it a second time is not.
        $this->assertSame(['mail App\Mail\Receipt', 'job App\Jobs\SendReceipt', 'mail App\Mail\Told', 'http POST api.stripe.com', 'mail App\Mail\Welcome', 'job App\Jobs\SendReceipt', 'update products', 'mail App\Mail\Receipt'], $order([]));
        // A finding on a route puts that route's places before the rest.
        $this->assertSame(['mail App\Mail\Receipt', 'job App\Jobs\SendReceipt', 'mail App\Mail\Welcome', 'mail App\Mail\Told', 'http POST api.stripe.com', 'job App\Jobs\SendReceipt', 'update products', 'mail App\Mail\Receipt'], $order([['route' => 'POST /teams', 'at' => null]]));
        // A finding on a line does the same for the place on that line.
        $this->assertSame(['mail App\Mail\Receipt', 'job App\Jobs\SendReceipt', 'update products', 'mail App\Mail\Told', 'http POST api.stripe.com', 'mail App\Mail\Welcome', 'job App\Jobs\SendReceipt', 'mail App\Mail\Receipt'], $order([['route' => 'GET /stock', 'at' => "{$old}/TakeStock.php:9"]]));
        // The same input gives the same order.
        $this->assertSame($order([['route' => 'POST /teams']]), $order([['route' => 'POST /teams']]));
    }

    public function test_requests_that_cannot_be_run_again_alone_and_what_a_queued_job_does_give_no_place()
    {
        $effects = [$this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'), $this->mailed(self::NEW.':4')];

        $this->assertCount(1, $this->points([$this->recorded('POST', '/orders', 302, $effects)]));
        $this->assertSame('test_customers_order', $this->points([$this->recorded('POST', '/orders', 302, $effects, ['test' => self::TEST.' with data set "a::b"'])])[0]['filter']);

        // Pest names a test by a sentence, and its filter reads the sentence, not the method it made.
        $filter = fn (string $test) => $this->points([$this->recorded('POST', '/orders', 302, $effects, ['test' => $test])])[0]['filter'];
        $this->assertSame('::it.orders(?: with data set |$)', $filter('P\Tests\Feature\OrderTest::__pest_evaluable_it_orders'));
        $this->assertSame('::it.orders(?: with data set |$)', $filter('P\Tests\Feature\OrderTest::__pest_evaluable_it_orders with data set "(\'a::b\')"'));
        $this->assertSame('::.orders.{1,2}....it.keeps.{2,3}total(?: with data set |$)', $filter("P\\Tests\\Feature\\OrderTest::__pest_evaluable__orders__\u{2192}_it_keeps___total"));
        $this->assertSame(1, preg_match('/'.$filter("P\\Tests\\Feature\\OrderTest::__pest_evaluable__orders__\u{2192}_it_keeps___total").'/i', "Tests\\Feature\\OrderTest::`orders` \u{2192} it keeps _total"));

        // No test, a name a filter cannot take, no place among the test's requests, or a trace cut short.
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['test' => null])]));
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['test' => 'Tests\Feature\OrderTest::it orders (twice)'])]));
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['n' => null])]));
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, $effects, ['cut' => true])]));

        // In use the job runs later, on a queue: its email and its save are not the request's.
        // The job is a place by itself, and so are the save it makes after its email and its wait on a queue.
        // Its email is a place only to see if the job hides the failure.
        $this->assertSame(['again', 'retry', 'later', 'send'], array_column($this->points([$this->recorded('POST', '/orders', 302, [
            $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'),
            ['kind' => 'job', 'what' => 'App\Jobs\SendReceipt', 'open' => 0, 'at' => self::NEW.':4'],
            [...$this->mailed('app/Jobs/SendReceipt.php:20'), 'job' => true],
            ['kind' => 'begin', 'open' => 1, 'job' => true],
            [...$this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:21', open: 1), 'job' => true],
            ['kind' => 'commit', 'open' => 0, 'job' => true],
        ])]), 'fails'));
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

    public function test_a_failure_the_app_catches_is_found_when_it_tells_no_one()
    {
        $saved = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $mail = $this->mailed(self::NEW.':4');
        $marks = $this->asked('update "orders" set "receipt_sent" = ? where "id" = ?', self::NEW.':5');
        $shape = ['shape' => ['flash status', 'to /orders/{order}']];

        // The email failed inside a try: the request left out what came after it, and answered as usual.
        $measured = $this->measure([$saved, $mail, $marks], 302, [$saved, $mail], extra: ['quiet' => true, ...$shape], was: $shape);

        $this->assertSame(['points' => 2, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key((array) $measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'failure_hidden', 'route' => 'POST /orders', 'failed' => 'mail App\Mail\Receipt', 'what' => 'mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);

        // A save the app catches is found the same way: put back with its transaction, or never made.
        $order = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':5', open: 1);
        $undone = $this->measure([['kind' => 'begin', 'open' => 1], $order, ['kind' => 'commit', 'open' => 0]], 302, [['kind' => 'begin', 'open' => 1], $order, ['kind' => 'rollback', 'open' => 0]], extra: ['quiet' => true, ...$shape], was: $shape);
        $never = $this->measure([$saved, $marks], 302, [$saved, $marks], extra: ['quiet' => true, ...$shape], was: $shape);

        $this->assertSame([[['failure_hidden', 'insert orders']], [['failure_hidden', 'update orders']]], array_map(
            fn (?array $measured) => array_map(fn (array $finding) => [$finding['kind'], $finding['failed']], $measured['findings'] ?? []),
            [$undone, $never],
        ));

        // The same in code the app already had is counted, not held against the change.
        $old = $this->mailed('app/Services/Receipts.php:9');
        $existing = $this->measure([$saved, $old], 302, [$saved, $old], extra: ['quiet' => true, ...$shape], was: $shape);
        $this->assertSame([1, 1, []], [$existing['run'] ?? null, $existing['existing'] ?? null, $existing['findings'] ?? null]);
    }

    public function test_a_failure_the_app_catches_is_no_finding_when_it_records_it_tells_the_person_or_does_something_about_it()
    {
        $saved = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $mail = $this->mailed(self::NEW.':4');
        $shape = ['shape' => ['flash status', 'to /orders/{order}']];
        $noted = $this->asked('insert into "failed_receipts" ("order_id") values (?)', self::NEW.':5');

        $measured = [
            // The app wrote to its log after the failure, or the log could not be seen.
            $this->measure([$saved, $mail], 302, [$saved, $mail], extra: $shape, was: $shape),
            // The person was told something else.
            $this->measure([$saved, $mail], 302, [$saved, $mail], extra: ['quiet' => true, 'shape' => ['flash problem', 'to /orders/{order}']], was: $shape),
            // The person got another answer.
            $this->measure([$saved, $mail], 422, [$saved, $mail], extra: ['quiet' => true, ...$shape], was: $shape),
            // The app did something it does not do when all works.
            $this->measure([$saved, $mail], 302, [$saved, $mail, $noted], extra: ['quiet' => true, ...$shape], was: $shape),
            // The shape of the answer is not known for both runs.
            $this->measure([$saved, $mail], 302, [$saved, $mail], extra: ['quiet' => true, ...$shape]),
            $this->measure([$saved, $mail], 302, [$saved, $mail], extra: ['quiet' => true], was: $shape),
            // A trace that is not whole cannot be compared.
            $this->measure([$saved, $mail], 302, [$saved, $mail], extra: ['quiet' => true, 'cut' => true, ...$shape], was: $shape),
        ];

        $this->assertSame(array_fill(0, 7, [1, []]), array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['findings'] ?? null], $measured));
    }

    public function test_a_file_the_app_stores_is_a_place_and_is_found_when_the_app_carries_on_without_it()
    {
        $saved = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $file = $this->stored(self::NEW.':4');
        $marks = $this->asked('update "orders" set "receipt" = ? where "id" = ?', self::NEW.':5');
        $shape = ['shape' => ['flash status', 'to /orders/{order}']];

        // A file is not counted among what the app sent: the save after it is no place, as it is after an email.
        $this->assertSame([[['send', 'file write', 'file']], ['send', 'save']], [
            array_map(fn (array $point) => [$point['fails'], $point['failed'], $point['fault']['kind']], $this->points([$this->recorded('POST', '/orders', 302, [$file, $marks])])),
            array_column($this->points([$this->recorded('POST', '/orders', 302, [$this->mailed(self::NEW.':4'), $marks])]), 'fails'),
        ]);

        // The disk gave false and threw nothing: the request did the same and answered as usual.
        $hidden = $this->measure([$file, $marks], 302, [$file, $marks], extra: ['quiet' => true, ...$shape], was: $shape);
        $this->assertSame([
            ['kind' => 'failure_hidden', 'route' => 'POST /orders', 'failed' => 'file write', 'what' => 'file write', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $hidden['findings'] ?? null);
        $this->assertSame(
            'POST /orders: when file write failed at '.self::NEW.':4, the app went on as if the file was stored: the request did nothing new, gave the same kind of answer as when all worked, and wrote nothing to the log (caused in '.self::TEST.'). '
                ."A write to a disk that fails gives false, and throws only when the disk's config has 'throw' => true. Ask what put(), store() or storeAs() gave back, or set 'throw' => true for the disk. Then do not go on as if the file is there: tell the person what did not happen, or let the job or the command fail.",
            AppFaults::finding($hidden['findings'][0]),
        );

        // A disk that throws ends the request in an error, and what it saved before stays.
        $thrown = $this->measure([$saved, $file], 500, [$saved, $file], point: 0);
        $this->assertSame([['saved_then_failed', 'file write', 'insert orders']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $thrown['findings'] ?? []));
        $this->assertStringEndsWith('Store the file before the save, so a write that fails leaves nothing behind. Or catch the failure, record it with report(), and tell the person what did not happen.', AppFaults::finding($thrown['findings'][0]));

        // An app that told the person, or wrote to its log, did not carry on as if the file is there.
        $this->assertSame([[1, []], [1, []]], array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['findings'] ?? null], [
            $this->measure([$file, $marks], 302, [$file], extra: ['quiet' => true, 'shape' => ['flash problem', 'to /orders/{order}']], was: $shape),
            $this->measure([$file, $marks], 302, [$file, $marks], extra: $shape, was: $shape),
        ]));
    }

    public function test_a_file_deleted_before_a_save_that_is_lost_is_found()
    {
        $gone = $this->stored(self::NEW.':3', 'delete');
        $save = $this->asked('delete from "orders" where "id" = ?', self::NEW.':4');

        // The delete of a file is not made to fail. The save after it is a place, as it is after a send.
        $this->assertSame([['save', 'delete orders', 'query']], array_map(fn (array $point) => [$point['fails'], $point['failed'], $point['fault']['kind']], $this->points([$this->recorded('POST', '/orders', 302, [$gone, $save])])));
        // A save before the delete of the file is no place.
        $this->assertSame([], $this->points([$this->recorded('POST', '/orders', 302, [$save, $this->stored(self::NEW.':5', 'delete')])]));

        // The save was refused and the person got an error: the row stays, and its file is gone.
        $steps = $this->measure([$gone, $save], 500, [$gone, $save]);
        $this->assertSame([
            ['kind' => 'file_gone', 'route' => 'POST /orders', 'failed' => 'delete orders', 'what' => 'file delete', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $steps['findings'] ?? null);
        $this->assertSame(
            'POST /orders: when delete orders failed at '.self::NEW.':4, the save was lost but the request had already deleted a file, and nothing puts it back: file delete (caused in '.self::TEST.'). '
                .'What the app kept still points to a file that is gone. Delete the file last, after the save is kept: after the transaction, or in DB::afterCommit().',
            AppFaults::finding($steps['findings'][0]),
        );

        // A transaction puts the save back, not the file.
        $inside = [['kind' => 'begin', 'open' => 1], $this->stored(self::NEW.':3', 'delete', open: 1), $this->asked('delete from "orders" where "id" = ?', self::NEW.':4', open: 1)];
        $undone = $this->measure([...$inside, ['kind' => 'commit', 'open' => 0]], 500, [...$inside, ['kind' => 'rollback', 'open' => 0]]);
        $this->assertSame([['file_gone', 'file delete']], array_map(fn (array $finding) => [$finding['kind'], $finding['what']], $undone['findings'] ?? []));

        // A file the app wrote before the lost save only stays unused, and an app that took the failure in is not read.
        $this->assertSame(['send'], array_column($this->points([$this->recorded('POST', '/orders', 302, [$this->stored(self::NEW.':3'), $save])]), 'fails'));
        $this->assertSame([], $this->measure([$gone, $save], 302, [$gone, $save])['findings'] ?? null);
    }

    public function test_a_file_deleted_before_a_send_that_fails_is_found_when_the_save_after_it_does_not_happen()
    {
        $gone = $this->stored(self::NEW.':3', 'delete');
        $new = $this->stored(self::NEW.':4');
        $save = $this->asked('update "orders" set "receipt" = ? where "id" = ?', self::NEW.':5');

        // The new file was not stored and the request stopped: the row still names the old file.
        $replaced = $this->measure([$gone, $new, $save], 302, [$gone, $new]);
        $this->assertSame([
            ['kind' => 'file_gone', 'route' => 'POST /orders', 'failed' => 'file write', 'what' => 'file delete', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $replaced['findings'] ?? null);
        $this->assertSame(
            'POST /orders: when file write failed at '.self::NEW.':4, the request did not make a save it makes when all works, but had already deleted a file, and nothing puts it back: file delete (caused in '.self::TEST.'). '
                .'What the app kept still points to a file that is gone. Delete the file last, after the save is kept: after the transaction, or in DB::afterCommit().',
            AppFaults::finding($replaced['findings'][0]),
        );

        // An email that fails after the delete stops the save the same way.
        $mail = $this->mailed(self::NEW.':4');
        $stopped = $this->measure([$gone, $mail, $save], 500, [$gone, $mail]);
        $this->assertSame([['file_gone', 'mail App\Mail\Receipt', 'file delete']], array_map(fn (array $finding) => [$finding['kind'], $finding['failed'], $finding['what']], $stopped['findings'] ?? []));

        // A save that a transaction put back is not made either.
        $inside = [['kind' => 'begin', 'open' => 1], $this->asked('update "orders" set "receipt" = ? where "id" = ?', self::NEW.':2', open: 1), $this->stored(self::NEW.':3', 'delete', open: 1), $this->mailed(self::NEW.':4', open: 1)];
        $undone = $this->measure([...$inside, ['kind' => 'commit', 'open' => 0]], 500, [...$inside, ['kind' => 'rollback', 'open' => 0]], point: 0);
        $this->assertContains('file_gone', array_column($undone['findings'] ?? [], 'kind'));

        // An app that made the save all the same kept nothing that names the old file.
        $this->assertNotContains('file_gone', array_column($this->measure([$gone, $new, $save], 302, [$gone, $new, $save])['findings'] ?? [], 'kind'));
        // An app that deletes the old file last had deleted nothing yet.
        $this->assertSame([], $this->measure([$new, $save, $gone], 302, [$new])['findings'] ?? null);
        // A request that made every save it makes kept nothing that names the file: only the kept save is said.
        $this->assertSame(['saved_then_failed'], array_column($this->measure([$save, $gone, $mail], 500, [$save, $gone, $mail])['findings'] ?? [], 'kind'));
    }

    public function test_only_the_last_save_in_steps_is_a_place_and_only_after_the_apps_code_saved_or_sent()
    {
        $order = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $items = $this->asked('insert into "order_items" ("order_id") values (?)', self::NEW.':4');
        $stock = $this->asked('update "products" set "left" = ?', self::NEW.':5');
        $failed = fn (array $effects) => array_column($this->points([$this->recorded('POST', '/orders', 302, $effects)]), 'failed');

        // When the last step fails, every step before it is seen to stay.
        $this->assertSame(['update products'], $failed([$order, $items, $stock]));
        // One save alone leaves nothing behind when it fails.
        $this->assertSame([], $failed([$this->asked('select * from "products"', self::NEW.':3'), $order]));
        // What the framework saved by itself is no step of the app's, before or as the save.
        $this->assertSame([], $failed([$this->asked('update "users" set "remember_token" = ?', null), $order]));
        $this->assertSame([], $failed([$order, $this->asked('update "sessions" set "payload" = ?', null)]));
        // A save that was rolled back did not stay, so nothing came before the one after it.
        $this->assertSame([], $failed([['kind' => 'begin', 'open' => 1], [...$order, 'open' => 1], ['kind' => 'rollback', 'open' => 0], $items]));
    }

    public function test_a_save_in_steps_that_fails_is_found_when_the_person_gets_an_error_and_an_earlier_step_stayed()
    {
        $order = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $token = $this->asked('update "users" set "remember_token" = ?', null);
        $items = $this->asked('insert into "order_items" ("order_id") values (?)', self::NEW.':4');

        $measured = $this->measure([$token, $order, $items], 500, [$token, $order, $items]);
        // The app caught the failure and answered in its own way: it took the failure in.
        $handled = $this->measure([$order, $items], 302, [$order, $items]);
        // The app tried the step again and it was saved.
        $again = $this->measure([$order, $items], 500, [$order, $items, $items]);

        // The order stayed without its items. What the framework saved by itself is not named.
        $this->assertSame([
            ['kind' => 'saved_in_part', 'route' => 'POST /orders', 'failed' => 'insert order_items', 'what' => 'insert orders', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings']);
        $this->assertSame([[1, []], [1, []]], array_map(fn (array $measured) => [$measured['run'], $measured['findings']], [$handled, $again]));
    }

    public function test_a_job_the_sync_queue_ran_is_a_place_when_it_sent_or_added_something_that_stayed()
    {
        $places = fn (array $effects) => array_map(
            fn (array $point) => [$point['fails'], $point['failed'], $point['at'], $point['own'], $point['fault']['effect'], $point['fault']['kind']],
            $this->points([$this->recorded('POST', '/orders', 302, $effects)]),
        );
        $receipt = $this->done($this->asked('insert into "receipts" ("order_id") values (?)', 'app/Jobs/SendReceipt.php:20'));
        $ran = $this->asked('select * from "orders" where "id" = ?', self::NEW.':3');
        // A job that sent or saved something also waits on a queue: that is a place of its own.
        $waits = ['later', 'job App\Jobs\SendReceipt', self::NEW.':4', true, 0, 'later'];

        // The change queues the job: the place is the change's.
        $this->assertSame([['again', 'job App\Jobs\SendReceipt', self::NEW.':4', true, 0, 'job'], $waits], $places([$this->job(self::NEW.':4'), $receipt]));
        // Code the app already had queues it: the place is tried after the change's own.
        $this->assertSame([
            ['again', 'job App\Jobs\SendReceipt', 'app/Actions/PlaceOrder.php:9', false, 1, 'job'],
            ['later', 'job App\Jobs\SendReceipt', 'app/Actions/PlaceOrder.php:9', false, 1, 'later'],
        ], $places([$ran, $this->job('app/Actions/PlaceOrder.php:9'), $receipt]));
        // The change wrote what the job does: the place is the change's too.
        $this->assertSame([true, true, true], array_column($places([$this->job('app/Actions/PlaceOrder.php:9'), $this->done($this->mailed(self::NEW.':4'))]), 3));

        // A job that makes a change that can be made again is no place to run twice. A job that only reads is no place at all.
        $this->assertSame([$waits], $places([$this->job(self::NEW.':4'), $this->done($this->asked('select * from "orders"', 'app/Jobs/SendReceipt.php:19')), $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:21'))]));
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $this->done($this->asked('select * from "orders"', 'app/Jobs/SendReceipt.php:19'))]));
        // An insert that says what to do when the row is there is made to run twice.
        $this->assertSame([$waits], $places([$this->job(self::NEW.':4'), $this->done($this->asked('insert into "receipts" ("order_id") values (?) on conflict ("order_id") do nothing', 'app/Jobs/SendReceipt.php:20'))]));
        $this->assertSame([$waits], $places([$this->job(self::NEW.':4'), $this->done($this->asked('insert ignore into `receipts` (`order_id`) values (?)', 'app/Jobs/SendReceipt.php:20'))]));
        // An insert the job put back did not stay.
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $this->done(['kind' => 'begin', 'open' => 1]), [...$receipt, 'open' => 1], $this->done(['kind' => 'rollback', 'open' => 0])]));
        // A job of the framework that delivers one email has no code of the app to make safe for a
        // second run. A worker still runs what the email says, so its wait on a queue is a place.
        $this->assertSame([$waits], $places([[...$this->job(self::NEW.':4'), 'delivers' => true], $this->done($this->mailed(null))]));
        // What a job inside the job does is the outer job's. The outer job queued it and then
        // saved: a second try queues it again, so that save is the outer job's second place.
        $this->assertSame([['again', 0], ['retry', 2], ['later', 0]], array_map(fn (array $place) => [$place[0], $place[4]], $places([$this->job(self::NEW.':4'), $this->done($this->job('app/Jobs/SendReceipt.php:18')), $receipt])));
    }

    public function test_a_job_that_ran_twice_is_found_when_it_sent_or_added_the_same_thing_both_times()
    {
        $run = [
            $this->done($this->asked('select * from "orders" where "id" = ?', 'app/Jobs/SendReceipt.php:19')),
            $this->done($this->asked('insert into "receipts" ("order_id") values (?)', 'app/Jobs/SendReceipt.php:20')),
            $this->done($this->mailed('app/Jobs/SendReceipt.php:21')),
            $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:22')),
        ];
        $again = $this->done([...$this->job(null), 'again' => true]);

        $measured = $this->measure([$this->job(self::NEW.':4'), ...$run], 302, [$this->job(self::NEW.':4'), ...$run, $again, ...$run]);

        // The row that is changed again stays as it was, so it is not named.
        // The change the job makes after its email is a second place, its wait on a queue a third and its email a fourth. They are not tried here.
        $this->assertSame(['points' => 4, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key($measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'done_twice', 'route' => 'POST /orders', 'failed' => 'job App\Jobs\SendReceipt', 'what' => 'insert receipts, mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings']);
    }

    public function test_a_job_that_ran_twice_is_no_finding_when_its_second_run_does_not_do_it_again()
    {
        $asks = $this->done($this->asked('select * from "receipts" where "order_id" = ?', 'app/Jobs/SendReceipt.php:19'));
        $run = [
            $asks,
            $this->done($this->asked('insert into "receipts" ("order_id") values (?)', 'app/Jobs/SendReceipt.php:20')),
            $this->done($this->mailed('app/Jobs/SendReceipt.php:21')),
        ];
        $normal = [$this->job(self::NEW.':4'), ...$run];
        $again = $this->done([...$this->job(null), 'again' => true]);

        // The job saw that it ran before, and stopped.
        $stopped = $this->measure($normal, 302, [...$normal, $again, $asks]);
        // The second run took another way: what it did is not what the first run did.
        $other = $this->measure($normal, 302, [...$normal, $again, $asks, $this->done($this->asked('insert into "receipt_retries" ("order_id") values (?)', 'app/Jobs/SendReceipt.php:30'))]);
        // The second run put its insert back.
        $undone = $this->measure($normal, 302, [...$normal, $again, $asks, $this->done(['kind' => 'begin', 'open' => 1]), [...$run[1], 'open' => 1], $this->done(['kind' => 'rollback', 'open' => 0])]);

        $this->assertSame([[1, []], [1, []], [1, []]], array_map(fn (array $measured) => [$measured['run'], $measured['findings']], [$stopped, $other, $undone]));
    }

    public function test_a_job_that_ran_twice_is_not_held_to_an_outside_call_the_service_can_take_twice()
    {
        $again = $this->done([...$this->job(null), 'again' => true]);
        $marks = $this->done($this->asked('update "orders" set "paid_at" = ?', 'app/Jobs/SendReceipt.php:23'));
        $mail = $this->done($this->mailed('app/Jobs/SendReceipt.php:22'));
        $plain = $this->done($this->called('app/Jobs/SendReceipt.php:21'));
        $keyed = $this->done($this->called('app/Jobs/SendReceipt.php:21', keyed: true));
        $read = $this->done($this->called('app/Jobs/SendReceipt.php:21', 'GET'));
        $put = $this->done($this->called('app/Jobs/SendReceipt.php:21', 'PUT'));
        $places = fn (array $call) => array_column($this->points([$this->recorded('POST', '/orders', 302, [$this->job(self::NEW.':4'), $call, $marks])]), 'fails');

        // A call that does not say which call it is can be done twice by the service:
        // the job is run twice, and tried again after its save failed.
        $this->assertSame(['again', 'retry', 'later', 'send'], $places($plain));
        // A call with an idempotency key, a call that only reads, and a call that puts the
        // same thing there again: a second run does no harm, so none is tried.
        $this->assertSame(array_fill(0, 3, ['later', 'send']), [$places($keyed), $places($read), $places($put)]);

        // A job that also sends an email is run twice for the email. Only the email is held against it.
        $run = [$keyed, $mail, $marks];
        $normal = [$this->job(self::NEW.':4'), ...$run];
        $twice = $this->measure($normal, 302, [...$normal, $again, ...$run]);
        $retried = $this->measure($normal, 500, [...$normal, $again, ...$run], point: 1);
        $this->assertSame([['done_twice' => 'mail App\\Mail\\Receipt'], ['sent_again' => 'mail App\\Mail\\Receipt']], array_map(fn (?array $measured) => array_column($measured['findings'] ?? [], 'what', 'kind'), [$twice, $retried]));

        // The same job with a call that has no key: the call is held against it too.
        $run = [$plain, $mail, $marks];
        $normal = [$this->job(self::NEW.':4'), ...$run];
        $this->assertSame(
            [['done_twice' => 'http POST pay.example, mail App\\Mail\\Receipt'], ['sent_again' => 'http POST pay.example, mail App\\Mail\\Receipt']],
            array_map(fn (?array $measured) => array_column($measured['findings'] ?? [], 'what', 'kind'), [$this->measure($normal, 302, [...$normal, $again, ...$run]), $this->measure($normal, 500, [...$normal, $again, ...$run], point: 1)]),
        );
    }

    public function test_the_last_save_a_job_makes_after_it_sent_is_a_place_tried_with_the_jobs()
    {
        $places = fn (array $effects) => array_map(
            fn (array $point) => [$point['fails'], $point['failed'], $point['at'], $point['fault']['effect'], $point['fault']['kind']],
            $this->points([$this->recorded('POST', '/orders', 302, $effects)]),
        );
        $asks = $this->done($this->asked('select * from "orders" where "id" = ?', 'app/Jobs/SendReceipt.php:19'));
        $mail = $this->done($this->mailed('app/Jobs/SendReceipt.php:21'));
        $marks = $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:22'));
        $logs = $this->done($this->asked('update "orders" set "tries" = ?', 'app/Jobs/SendReceipt.php:23'));
        $stock = $this->asked('update "products" set "left" = ?', self::NEW.':5');

        // The last save after the email is the place; it is tried with the job, before the request's own save.
        $this->assertSame([
            ['again', 'job App\Jobs\SendReceipt', self::NEW.':4', 2, 'job'],
            ['retry', 'job App\Jobs\SendReceipt', self::NEW.':4', 6, 'query'],
            ['later', 'job App\Jobs\SendReceipt', self::NEW.':4', 2, 'later'],
            ['save', 'update products', self::NEW.':5', 1, 'query'],
            ['send', 'mail App\Mail\Receipt', 'app/Jobs/SendReceipt.php:21', 4, 'mail'],
        ], $places([$this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'), $stock, $this->job(self::NEW.':4'), $asks, $mail, $marks, $logs]));
        // A job that saves first and sends last has no save to lose after the send.
        $this->assertSame(['again', 'later', 'send'], array_column($places([$this->job(self::NEW.':4'), $marks, $mail]), 0));
        // A job of the framework has no save of the app to lose. Only its wait on a queue is a place.
        $this->assertSame(['later'], array_column($places([[...$this->job(self::NEW.':4'), 'delivers' => true], $mail, $marks]), 0));
    }

    public function test_a_job_tried_again_after_its_save_failed_is_found_when_it_sent_the_same_thing_again()
    {
        $asks = $this->done($this->asked('select * from "orders" where "receipt_sent_at" is null', 'app/Jobs/SendReceipt.php:19'));
        $mail = $this->done($this->mailed('app/Jobs/SendReceipt.php:21'));
        $marks = $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:22'));
        $normal = [$this->job(self::NEW.':4'), $asks, $mail, $marks];
        $again = $this->done([...$this->job(null), 'again' => true]);

        $measured = $this->measure($normal, 500, [...$normal, $again, $asks, $mail, $marks], point: 1);

        $this->assertSame(['points' => 4, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key((array) $measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'sent_again', 'route' => 'POST /orders', 'failed' => 'job App\Jobs\SendReceipt', 'what' => 'mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);
    }

    public function test_a_job_tried_again_after_its_email_failed_is_found_when_the_second_try_does_not_send()
    {
        $asks = $this->done($this->asked('select * from "orders" where "receipt_sent_at" is null', 'app/Jobs/SendReceipt.php:19'));
        $marks = $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:20'));
        // The change wrote the line that sends.
        $mail = $this->done($this->mailed(self::NEW.':4'));
        $normal = [$this->job('app/Actions/PlaceOrder.php:9'), $asks, $marks, $mail];
        $again = $this->done([...$this->job(null), 'again' => true]);
        $send = (int) array_search('send', array_column($this->points([$this->recorded('POST', '/orders', 302, $normal)]), 'fails'), true);
        $this->assertSame(2, $send);

        // The second try saw the mark of the first, and stopped.
        $measured = $this->measure($normal, 500, [...$normal, $again, $asks], point: $send);

        $this->assertSame([
            ['kind' => 'never_sent', 'route' => 'POST /orders', 'failed' => 'mail App\Mail\Receipt', 'what' => 'mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST, 'job' => true],
        ], $measured['findings'] ?? null);
        $this->assertSame(
            'POST /orders: when mail App\Mail\Receipt failed at '.self::NEW.':4 and the job was tried again, the second try did not send it: what the first try left behind made the job stop, so it is never sent (caused in '.self::TEST.'). '
                .'A queue tries a failed job again, and that try must send what the first could not. When the send fails, take back what the job saved before it: catch the failure, undo the save, and throw the failure again.',
            AppFaults::finding($measured['findings'][0] ?? []),
        );

        // The job took the mark back, so the second try sent.
        $back = $this->done($this->asked('update "orders" set "receipt_sent_at" = null', 'app/Jobs/SendReceipt.php:24'));
        $sent = $this->measure($normal, 500, [...$normal, $back, $again, $asks, $marks, $mail], point: $send);
        // The job took the failure in: no queue tries it again. It recorded the failure, so it did not hide it.
        $tookIn = $this->measure($normal, 302, $normal, point: $send);
        // A second try that is not whole in the trace says nothing.
        $cut = $this->measure($normal, 500, [...$normal, $again, $asks], point: $send, extra: ['cut' => true]);

        $this->assertSame([[1, []], [1, []], [1, []]], array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['findings'] ?? null], [$sent, $tookIn, $cut]));
    }

    public function test_a_request_that_sends_to_many_is_found_when_the_first_failure_stops_the_rest()
    {
        $mail = $this->mailed(self::NEW.':4');
        $normal = [$mail, $mail, $mail];

        // The first email from the line is the one made to fail.
        $point = $this->points([$this->recorded('POST', '/orders', 302, $normal)])[0];
        $this->assertSame(['send', 0, 3], [$point['fails'], $point['fault']['effect'], $point['times']]);

        $measured = $this->measure($normal, 500, [$mail]);

        $this->assertSame([
            ['kind' => 'rest_not_sent', 'route' => 'POST /orders', 'failed' => 'mail App\Mail\Receipt', 'what' => 'mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);
        $this->assertSame(
            'POST /orders: when mail App\Mail\Receipt failed at '.self::NEW.':4, one failure stopped the rest: the request sends from that line more than once when all works, and it did not send the others (caused in '.self::TEST.'). '
                .'One failure must not stop the rest. Queue each one: Mail::to()->queue(), a notification that implements ShouldQueue, or one job for each person. Or catch the failure for each one, record it with report(), and go on with the next.',
            AppFaults::finding($measured['findings'][0] ?? []),
        );

        // The app caught the failure and left the loop: the rest is still not sent.
        $left = $this->measure($normal, 302, [$mail]);
        // The app went on with the next one.
        $wentOn = $this->measure($normal, 302, [$mail, $mail, $mail]);
        // One email from the line has no others to stop.
        $one = $this->measure([$mail], 500, [$mail]);
        // A trace that is not whole does not show what was sent after.
        $cut = $this->measure($normal, 500, [$mail], extra: ['cut' => true]);
        // A loop of outside calls can be right to stop: one call can need the one before it.
        $call = $this->called(self::NEW.':4');
        $calls = $this->measure([$call, $call, $call], 500, [$call]);

        $this->assertSame([['rest_not_sent'], [], [], [], []], array_map(fn (?array $measured) => array_column($measured['findings'] ?? [], 'kind'), [$left, $wentOn, $one, $cut, $calls]));
    }

    public function test_a_job_that_sends_to_many_is_found_when_it_sends_the_first_ones_again_after_one_email_failed()
    {
        $mail = $this->done($this->mailed(self::NEW.':4'));
        $job = $this->job('app/Actions/PlaceOrder.php:9');
        $again = $this->done([...$this->job(null), 'again' => true]);
        $normal = [$job, $mail, $mail, $mail];
        $send = fn (array $effects) => (int) array_search('send', array_column($this->points([$this->recorded('POST', '/orders', 302, $effects)]), 'fails'), true);

        // The last email from the line is the one made to fail.
        $point = $this->points([$this->recorded('POST', '/orders', 302, $normal)])[$send($normal)];
        $this->assertSame(['send', 3, 3], [$point['fails'], $point['fault']['effect'], $point['times']]);

        // The second try started from the top.
        $measured = $this->measure($normal, 500, [...$normal, $again, $mail, $mail, $mail], point: $send($normal));

        $this->assertSame([
            ['kind' => 'sent_again', 'route' => 'POST /orders', 'failed' => 'mail App\Mail\Receipt', 'what' => 'mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST, 'job' => true],
        ], $measured['findings'] ?? null);
        $this->assertSame(
            'POST /orders: when mail App\Mail\Receipt failed at '.self::NEW.':4 and the job was tried again, the job started from the top and sent again what it had sent before the failure: mail App\Mail\Receipt (caused in '.self::TEST.'). '
                .'A queue tries a failed job again from the top. Send each email from its own job (queue the email, or dispatch one job for each person). Or record each one before the job sends it, and take only that record back when its send fails.',
            AppFaults::finding($measured['findings'][0] ?? []),
        );

        // The second try sent only what the first could not.
        $resumed = $this->measure($normal, 500, [...$normal, $again, $mail], point: $send($normal));
        // One job for each person: only the job that failed is tried again.
        $apart = [$job, $mail, $job, $mail, $job, $mail];
        $each = $this->measure($apart, 500, [...$apart, $again, $mail], point: $send($apart));
        // A job the job dispatched runs by itself in use: its email is no place of the job around it.
        $inside = [$job, $this->done($this->job('app/Jobs/SendReceipt.php:9')), $mail];

        $this->assertSame([[1, []], [1, []]], array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['findings'] ?? null], [$resumed, $each]));
        $this->assertNotContains('send', array_column($this->points([$this->recorded('POST', '/orders', 302, $inside)]), 'fails'));

        // A test where the job sends more times gives the place: it shows more of what is sent again.
        $points = $this->points([
            $this->recorded('POST', '/orders', 302, [$job, $mail], ['test' => 'Tests\Feature\OrderTest::test_one']),
            $this->recorded('POST', '/orders', 302, [$job, $mail, $mail], ['test' => 'Tests\Feature\OrderTest::test_two']),
            $this->recorded('POST', '/orders', 302, [$job, $mail, $mail], ['test' => 'Tests\Feature\OrderTest::test_three']),
        ]);
        $point = $points[(int) array_search('send', array_column($points, 'fails'), true)];
        $this->assertSame(['test_two', 2, 2], [$point['filter'], $point['fault']['effect'], $point['times']]);
    }

    public function test_a_job_tried_again_is_no_finding_when_it_sends_once_and_missed_when_its_second_run_is_not_whole()
    {
        $asks = $this->done($this->asked('select * from "orders" where "receipt_sent_at" is null', 'app/Jobs/SendReceipt.php:19'));
        $mail = $this->done($this->mailed('app/Jobs/SendReceipt.php:21'));
        $marks = $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:22'));
        $normal = [$this->job(self::NEW.':4'), $asks, $mail, $marks];
        $again = $this->done([...$this->job(null), 'again' => true]);

        // The job took the failure in: no queue tries it again.
        $tookIn = $this->measure($normal, 302, $normal, point: 1);
        // The second try saw what the first had done, and did not send.
        $stopped = $this->measure($normal, 500, [...$normal, $again, $asks, $marks], point: 1);
        // The second try sent something else, from another line.
        $other = $this->measure($normal, 500, [...$normal, $again, $asks, $this->done($this->mailed('app/Jobs/SendReceipt.php:30')), $marks], point: 1);

        $this->assertSame([[1, []], [1, []], [1, []]], array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['findings'] ?? null], [$tookIn, $stopped, $other]));

        // A job that sends to many marks each one before it sends it. The second try went on where
        // the first stopped: the same line sent in both tries, but each email left once.
        $many = [$this->job(self::NEW.':4'), $marks, $mail, $marks, $mail];
        $retry = (int) array_search('retry', array_column($this->points([$this->recorded('POST', '/orders', 302, $many)]), 'fails'), true);
        $wentOn = $this->measure($many, 500, [$this->job(self::NEW.':4'), $marks, $mail, $marks, $again, $marks, $mail], point: $retry);
        // The second try started from the top.
        $fromTop = $this->measure($many, 500, [$this->job(self::NEW.':4'), $marks, $mail, $marks, $again, $marks, $mail, $marks, $mail], point: $retry);
        $this->assertSame([[1, []], [1, ['sent_again']]], array_map(fn (?array $measured) => [$measured['run'] ?? null, array_column($measured['findings'] ?? [], 'kind')], [$wentOn, $fromTop]));

        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, $normal)), self::PATCH);
        $cut = AppFaults::measure($points, [1 => AppTraces::parse($this->recorded('POST', '/orders', 500, [...$normal, $again, $asks], ['fault' => 3, 'cut' => true]))], self::PATCH);
        $this->assertSame([0, 1, []], [$cut['run'] ?? null, $cut['missed'] ?? null, $cut['findings'] ?? null]);
    }

    public function test_an_outside_call_made_again_after_no_answer_is_found()
    {
        $call = $this->called(self::NEW.':4');

        $this->assertSame([[
            'kind' => 'called_again', 'route' => 'POST /orders', 'failed' => 'http POST pay.example', 'what' => 'http POST pay.example', 'at' => self::NEW.':4', 'test' => self::TEST,
        ]], $this->measure([$call], 302, [$call, $call])['findings']);

        // The person also got an error after the app saved: both are said.
        $order = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $this->assertSame(['saved_then_failed', 'called_again'], array_column($this->measure([$order, $call], 500, [$order, $call, $call])['findings'], 'kind'));
    }

    public function test_an_outside_call_made_again_is_no_finding_when_the_service_can_take_it_twice()
    {
        $call = $this->called(self::NEW.':4');
        $keyed = $this->called(self::NEW.':4', keyed: true);
        $read = $this->called(self::NEW.':4', 'GET');
        $put = $this->called(self::NEW.':4', 'PUT');

        $measured = [
            // The call says which call it is.
            $this->measure([$keyed], 302, [$keyed, $keyed]),
            // A call that only reads, and a call that puts the same thing there again.
            $this->measure([$read], 302, [$read, $read]),
            $this->measure([$put], 302, [$put, $put]),
            // A loop that carried on with the next call makes no more calls than before.
            $this->measure([$call, $call], 302, [$call, $call]),
            // The app did not try again.
            $this->measure([$call], 500, [$call]),
            // The call after it is another call, from another line.
            $this->measure([$call, $this->called(self::NEW.':5')], 302, [$call, $this->called(self::NEW.':5')]),
        ];

        $this->assertSame(array_fill(0, 6, [1, []]), array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['findings'] ?? null], $measured));
    }

    public function test_an_outside_call_the_apps_code_makes_itself_and_does_more_after_is_a_place()
    {
        $call = $this->called('app/Http/Controllers/OrderController.php:1', direct: true);
        $marks = $this->asked('update "orders" set "paid" = ? where "id" = ?', self::NEW.':5');
        $places = fn (array $effects) => array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind'], $point['own']], $this->points([$this->recorded('POST', '/orders', 302, $effects)]));
        $answers = fn (array $effects) => array_values(array_filter($places($effects), fn (array $place) => $place[0] === 'answer'));

        // The change wrote what the request does after the call, so the place is the change's.
        // It is tried with the sends, before the saves.
        $this->assertSame([['answer', 0, 'answer', true], ['save', 1, 'query', true], ['send', 0, 'http', false]], $places([$call, $marks]));
        // A package made the call for the app: the app's code does not get the answer.
        $this->assertSame([['save', 1, 'query', true], ['send', 0, 'http', false]], $places([$this->called('app/Http/Controllers/OrderController.php:1'), $marks]));
        // Nothing of the app's code is sent or saved after the call.
        $this->assertSame([['send', 0, 'http', true]], $places([$this->called(self::NEW.':4', direct: true)]));
        $this->assertSame([['send', 0, 'http', true]], $places([$this->called(self::NEW.':4', direct: true), $this->asked('select * from "orders"', self::NEW.':5'), $this->asked('update "sessions" set "payload" = ?', null)]));

        // After a call a job made, only the rest of that job counts.
        $job = $this->job(self::NEW.':4');
        $inJob = $this->done($this->called('app/Jobs/SendReceipt.php:19', direct: true));
        $this->assertSame([], $answers([$job, $inJob, $marks]));
        $this->assertSame([['answer', 1, 'answer', false]], $answers([$job, $inJob, $this->done($this->mailed('app/Jobs/SendReceipt.php:20'))]));
    }

    public function test_an_outside_call_answered_with_an_error_is_found_when_the_app_carries_on_without_asking()
    {
        $call = $this->called(self::NEW.':4', direct: true);
        $marks = $this->asked('update "orders" set "paid" = ? where "id" = ?', self::NEW.':5');
        $mail = $this->mailed(self::NEW.':5');

        $measured = $this->answered([$call, $marks, $mail], 302, [$call, $marks, $mail]);

        $this->assertSame(['points' => 4, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key((array) $measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'answer_not_checked', 'route' => 'POST /orders', 'failed' => 'http POST pay.example', 'what' => 'update orders, mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);

        // Code the app already had made the call and carried on: counted, not held against the change.
        $old = $this->called('app/Services/Pay.php:9', direct: true);
        $oldMarks = $this->asked('update "orders" set "paid" = ? where "id" = ?', 'app/Services/Pay.php:12');
        $order = $this->asked('insert into "orders" ("total") values (?)', self::NEW.':3');
        $existing = $this->answered([$order, $old, $oldMarks], 302, [$order, $old, $oldMarks]);
        $this->assertSame([1, 1, []], [$existing['run'] ?? null, $existing['existing'] ?? null, $existing['findings'] ?? null]);
    }

    public function test_an_outside_call_answered_with_an_error_is_no_finding_when_the_app_asked_or_did_something_else()
    {
        $call = $this->called(self::NEW.':4', direct: true);
        $marks = $this->asked('update "orders" set "paid" = ? where "id" = ?', self::NEW.':5');
        $fails = $this->asked('update "orders" set "failed_at" = ? where "id" = ?', self::NEW.':3');

        $measured = [
            // The app asked the answer for its status, and carried on in its own way.
            $this->answered([$call, $marks], 302, [$call, $marks], ['asked' => true]),
            // The app stopped: the person got another answer, and nothing was marked.
            $this->answered([$call, $marks], 502, [$call]),
            // The app marked the order in another way, from another line.
            $this->answered([$call, $marks], 302, [$call, $fails]),
        ];

        $this->assertSame(array_fill(0, 3, [1, 0, []]), array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['missed'] ?? null, $measured['findings'] ?? null], $measured));

        // A trace that is not whole cannot be compared, and a call that got its own answer is not the place.
        $cut = $this->answered([$call, $marks], 302, [$call, $marks], ['cut' => true]);
        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, [$call, $marks])), self::PATCH);
        $unanswered = AppFaults::measure($points, [0 => AppTraces::parse($this->recorded('POST', '/orders', 302, [$call, $marks]))], self::PATCH);
        $this->assertSame([[0, 1, []], [0, 1, []]], array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['missed'] ?? null, $measured['findings'] ?? null], [$cut, $unanswered]));
    }

    public function test_a_job_the_request_does_more_after_is_a_place()
    {
        $job = $this->job('app/Http/Controllers/OrderController.php:1');
        $adds = $this->done($this->asked('insert into "invoices" ("order_id") values (?)', 'app/Jobs/SendReceipt.php:19'));
        $reads = $this->asked('select * from "invoices" where "order_id" = ?', self::NEW.':5');
        $places = fn (array $effects) => array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind'], $point['own']], $this->points([$this->recorded('POST', '/orders', 302, $effects)]));

        // The change wrote what the request does after the job, so the place is the change's.
        $this->assertSame([['later', 0, 'later', true], ['again', 0, 'job', false]], $places([$job, $adds, $reads]));
        // Nothing of the app's code comes after the job. The job saved, so it is still a place: a worker runs it.
        $this->assertSame([['again', 0, 'job', true], ['later', 0, 'later', true]], $places([$this->job(self::NEW.':4'), $adds]));
        // The job did nothing that was seen.
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $reads]));
        // What the framework does by itself after the job is not the app's.
        $this->assertSame([['again', 0, 'job', true], ['later', 0, 'later', true]], $places([$this->job(self::NEW.':4'), $adds, $this->asked('update "sessions" set "payload" = ?', null)]));
    }

    public function test_a_job_that_waits_is_found_when_the_job_does_not_do_the_same_the_way_a_worker_runs_it()
    {
        $job = $this->job(self::NEW.':4');
        $thanks = $this->done($this->mailed('app/Jobs/SendReceipt.php:20'));
        $marks = $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:21'));
        $asks = $this->asked('select * from "customers" where "id" = ?', self::NEW.':5');
        $mail = $this->mailed(self::NEW.':5');

        // The job found no one to send to, and stopped.
        $measured = $this->heldBack([$job, $thanks, $marks], 302, [$job]);

        $this->assertSame(['points' => 4, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key((array) $measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'job_needs_request', 'route' => 'POST /orders', 'failed' => 'job App\Jobs\SendReceipt', 'what' => 'missing mail App\Mail\Receipt, missing update orders', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);

        // The job did the same: it was given what it needs.
        $clean = $this->heldBack([$job, $thanks, $marks], 302, [$job, $thanks, $marks]);
        $this->assertSame(['points' => 4, 'run' => 1, 'missed' => 0, 'existing' => 0, 'findings' => []], $clean);

        // The request did not wait for the job, and the job did not do the same: each is said by itself.
        $both = $this->heldBack([$job, $thanks, $asks, $mail], 302, [$job, $asks]);
        $this->assertSame([
            ['needs_job_done', 'missing mail App\Mail\Receipt'],
            ['job_needs_request', 'missing mail App\Mail\Receipt'],
        ], array_map(fn (array $finding) => [$finding['kind'], $finding['what']], $both['findings'] ?? []));

        // Code the app already had queues the job and wrote what it does: it is counted, not held against the change.
        $old = $this->job('app/Actions/PlaceOrder.php:9');
        $ran = $this->asked('select * from "orders" where "id" = ?', self::NEW.':3');
        $existing = $this->heldBack([$ran, $old, $thanks], 302, [$ran, $old], ['fault' => 1]);
        $this->assertSame([1, 0, 1, []], [$existing['run'] ?? null, $existing['missed'] ?? null, $existing['existing'] ?? null, $existing['findings'] ?? null]);
    }

    public function test_a_job_that_waits_is_found_when_the_request_does_not_do_the_same_without_it()
    {
        $job = $this->job(self::NEW.':4');
        $adds = $this->done($this->asked('insert into "invoices" ("order_id") values (?)', 'app/Jobs/SendReceipt.php:19'));
        $reads = $this->asked('select * from "invoices" where "order_id" = ?', self::NEW.':5');
        $asks = $this->asked('select * from "customers" where "id" = ?', self::NEW.':5');
        $mail = $this->mailed(self::NEW.':5');

        // The request found no invoice, and sent nothing.
        $measured = $this->heldBack([$job, $adds, $reads, $mail], 302, [$job, $reads, $adds]);

        $this->assertSame(['points' => 3, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key((array) $measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'needs_job_done', 'route' => 'POST /orders', 'failed' => 'job App\Jobs\SendReceipt', 'what' => 'missing mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);

        // The request failed without the invoice. The job still ran later.
        $failed = $this->heldBack([$job, $adds, $reads, $mail], 500, [$job, $reads, $adds]);
        $this->assertSame(['answered 500, not 302, missing mail App\Mail\Receipt'], array_column($failed['findings'] ?? [], 'what'));

        // The job and the rest of the request use no table together: the same shape is the same result.
        $clean = $this->heldBack([$job, $adds, $asks, $mail], 302, [$job, $asks, $mail, $adds]);
        $this->assertSame(['points' => 3, 'run' => 1, 'missed' => 0, 'existing' => 0, 'findings' => []], $clean);

        // The request reads the table the job saves to: it can have read something else.
        $shared = $this->heldBack([$job, $adds, $reads, $mail], 302, [$job, $reads, $mail, $adds]);
        $this->assertSame(['points' => 3, 'run' => 0, 'missed' => 1, 'existing' => 0, 'findings' => []], $shared);

        // A trace that is not whole cannot be compared, and a job that was not held back is not the place.
        $cut = $this->heldBack([$job, $adds, $asks, $mail], 302, [$job, $asks, $mail], ['cut' => true]);
        $ranInPlace = AppFaults::measure(
            AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, [$job, $adds, $asks, $mail])), self::PATCH),
            [2 => AppTraces::parse($this->recorded('POST', '/orders', 302, [$job, $adds, $asks, $mail]))],
            self::PATCH,
        );
        $this->assertSame([[0, 1, []], [0, 1, []]], array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['missed'] ?? null, $measured['findings'] ?? null], [$cut, $ranInPlace]));
    }

    public function test_an_event_with_found_listeners_is_a_place_named_in_the_fault_and_the_changes_when_it_dispatches_it()
    {
        $insert = $this->heard($this->asked('insert into "invoices" ("order_id") values (?)', 'app/Listeners/MakeInvoice.php:12'), 'MakeInvoice');
        $mail = $this->heard($this->mailed('app/Listeners/SendReceipt.php:14'), 'SendReceipt');

        // No line of the change did anything, but one dispatched the event.
        $points = $this->points([$this->recorded('POST', '/orders', 302, [$insert, $mail], $this->dispatched(self::NEW.':4'))]);

        $this->assertSame([['reorder', 'event App\Events\OrderPlaced', self::NEW.':4', true], ['send', 'mail App\Mail\Receipt', 'app/Listeners/SendReceipt.php:14', false]], array_map(fn (array $point) => [$point['fails'], $point['failed'], $point['at'], $point['own']], $points));
        $this->assertSame(['test' => self::TEST, 'request' => 0, 'effect' => 0, 'kind' => 'event', 'what' => 'App\Events\OrderPlaced'], $points[0]['fault']);

        // The app already dispatched it: the place is the change's only when the change wrote what a listener does.
        $old = fn (array $effects) => $this->points([$this->recorded('POST', '/orders', 302, $effects, $this->dispatched('app/Http/Controllers/OrderController.php:1'))]);
        $this->assertSame([], $old([$insert, $mail]));
        // The email is tried first, then the event, then the save in steps.
        $this->assertSame([['send', false], ['reorder', false], ['save', false]], array_map(fn (array $point) => [$point['fails'], $point['own']], $old([$this->asked('update "orders" set "paid" = ?', self::NEW.':3'), $insert, $mail])));
        $this->assertSame([['send', true], ['reorder', true]], array_map(fn (array $point) => [$point['fails'], $point['own']], $old([$insert, $this->heard($this->mailed(self::NEW.':4'), 'SendReceipt')])));
    }

    public function test_an_event_is_found_when_the_request_does_not_do_the_same_in_the_reverse_order()
    {
        $insert = $this->heard($this->asked('insert into "invoices" ("order_id") values (?)', 'app/Listeners/MakeInvoice.php:12'), 'MakeInvoice');
        $asks = $this->heard($this->asked('select * from "invoices" where "order_id" = ?', 'app/Listeners/SendReceipt.php:11'), 'SendReceipt');
        $mail = $this->heard($this->mailed('app/Listeners/SendReceipt.php:14'), 'SendReceipt');

        // The receipt found no invoice, and was not sent.
        $measured = $this->reordered([$insert, $asks, $mail], 302, [$asks, $insert]);

        $this->assertSame(['points' => 2, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key((array) $measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'depends_on_order', 'route' => 'POST /orders', 'failed' => 'event App\Events\OrderPlaced', 'what' => 'missing mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);

        // The receipt failed, so nothing after it ran. A save that was put back is not counted.
        $failed = $this->reordered([$insert, $asks, $mail], 500, [$asks]);
        $this->assertSame(['answered 500, not 302, missing insert invoices, missing mail App\Mail\Receipt'], array_column($failed['findings'] ?? [], 'what'));

        // It sent one more from the same line.
        $more = $this->reordered([$insert, $mail], 302, [$mail, $mail, $insert]);
        $this->assertSame(['added mail App\Mail\Receipt'], array_column($more['findings'] ?? [], 'what'));

        // The app already dispatched the event and no line of the change is in what changed.
        $existing = $this->reordered([$this->asked('update "orders" set "paid" = ?', self::NEW.':3'), $insert, $asks, $mail], 302, [$this->asked('update "orders" set "paid" = ?', self::NEW.':3'), $asks, $insert], at: null);
        $this->assertSame([1, 1, []], [$existing['run'] ?? null, $existing['existing'] ?? null, $existing['findings'] ?? null]);
    }

    public function test_an_event_is_clean_when_both_orders_do_the_same_and_missed_when_the_shape_cannot_tell()
    {
        $insert = $this->heard($this->asked('insert into "invoices" ("order_id") values (?)', 'app/Listeners/MakeInvoice.php:12'), 'MakeInvoice');
        $asks = $this->heard($this->asked('select * from "invoices" where "order_id" = ?', 'app/Listeners/SendReceipt.php:11'), 'SendReceipt');
        $reads = $this->heard($this->asked('select * from "customers" where "id" = ?', 'app/Listeners/SendReceipt.php:11'), 'SendReceipt');
        $mail = $this->heard($this->mailed('app/Listeners/SendReceipt.php:14'), 'SendReceipt');

        // The listeners use no table together: the same shape is the same result.
        $clean = $this->reordered([$insert, $reads, $mail], 302, [$reads, $mail, $insert]);
        $this->assertSame(['points' => 2, 'run' => 1, 'missed' => 0, 'existing' => 0, 'findings' => []], $clean);

        // One listener reads the table the other saves to: the email can say something else.
        $shared = $this->reordered([$insert, $asks, $mail], 302, [$asks, $mail, $insert]);
        $this->assertSame(['points' => 2, 'run' => 0, 'missed' => 1, 'existing' => 0, 'findings' => []], $shared);

        // They use one table only in the reverse order.
        $later = $this->reordered([$insert, $reads, $mail], 302, [$asks, $mail, $insert]);
        $this->assertSame([0, 1], [$later['run'] ?? null, $later['missed'] ?? null]);

        // Two listeners that only read one table do not change it.
        $both = $this->heard($this->asked('select * from "customers" where "id" = ?', 'app/Listeners/MakeInvoice.php:9'), 'MakeInvoice');
        $this->assertSame([1, 0], array_values(array_intersect_key((array) $this->reordered([$both, $insert, $reads, $mail], 302, [$reads, $mail, $both, $insert]), ['run' => 1, 'missed' => 1])));

        // A trace that is not whole cannot be compared, and an event that did not come is not the place.
        $cut = $this->reordered([$insert, $reads, $mail], 302, [$reads, $mail], extra: ['cut' => true]);
        $away = $this->reordered([$insert, $reads, $mail], 302, [$insert, $reads, $mail], extra: ['fault' => 3]);
        $this->assertSame([[0, 1, []], [0, 1, []]], array_map(fn (?array $measured) => [$measured['run'] ?? null, $measured['missed'] ?? null, $measured['findings'] ?? null], [$cut, $away]));
    }

    public function test_a_job_whose_second_run_is_not_whole_in_the_trace_is_missed()
    {
        $run = [$this->done($this->mailed('app/Jobs/SendReceipt.php:21'))];
        $normal = [$this->job(self::NEW.':4'), ...$run];
        $points = AppFaults::points(AppTraces::parse($this->recorded('POST', '/orders', 302, $normal)), self::PATCH);
        $again = $this->done([...$this->job(null), 'again' => true]);

        // The trace was cut short during the second run.
        $cut = AppFaults::measure($points, [AppTraces::parse($this->recorded('POST', '/orders', 302, [...$normal, $again], ['fault' => 0, 'cut' => true]))], self::PATCH);
        // The job did not run a second time: the test took another way.
        $notRun = AppFaults::measure($points, [AppTraces::parse($this->recorded('POST', '/orders', 302, $normal))], self::PATCH);

        $this->assertSame([[0, 1, []], [0, 1, []]], array_map(fn (array $measured) => [$measured['run'], $measured['missed'], $measured['findings']], [$cut, $notRun]));
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

    public function test_what_the_owner_wants_is_set_aside_by_what_it_is_and_the_coder_is_told_the_fix()
    {
        $kept = ['kind' => AppFaults::SAVED_THEN_FAILED, 'route' => 'POST /orders', 'failed' => 'mail App\Mail\Receipt', 'what' => 'insert orders', 'at' => self::NEW.':4', 'test' => self::TEST];
        $twice = ['kind' => AppFaults::DONE_TWICE, 'route' => 'POST /orders', 'failed' => 'job App\Jobs\SendReceipt', 'what' => 'mail App\Mail\Receipt', 'at' => null, 'test' => self::TEST];
        $measured = ['points' => 3, 'run' => 3, 'missed' => 0, 'existing' => 0, 'findings' => [$kept, $twice]];

        // The line is not part of what a finding is: it moves while the change is fixed.
        $this->assertSame('saved_then_failed|POST /orders|mail App\Mail\Receipt', AppFaults::identity($kept));
        $this->assertSame(AppFaults::identity($kept), AppFaults::identity([...$kept, 'at' => self::NEW.':9', 'what' => 'insert orders, insert order_items']));

        $this->assertSame($measured, AppFaults::without($measured, []));
        $this->assertNull(AppFaults::without(null, [AppFaults::identity($kept)]));
        $this->assertSame([...$measured, 'findings' => [$twice], 'accepted' => 1], AppFaults::without($measured, [AppFaults::identity($kept), 'saved_then_failed|POST /other|mail App\Mail\Receipt']));

        $this->assertSame(
            'POST /orders: when mail App\Mail\Receipt failed at '.self::NEW.':4, the request ended in a server error but had already saved: insert orders (caused in '.self::TEST.'). '
                .'A person who sees the error tries again, and the save happens twice. Queue what the request sends, after the save is kept. Or catch the failure, record it with report(), and tell the person what did not happen.',
            AppFaults::finding($kept),
        );
        $this->assertSame(
            'POST /orders: when job App\Jobs\SendReceipt ran a second time, it sent or added the same thing again: mail App\Mail\Receipt (caused in '.self::TEST.'). '
                .'A queue gives a job to a worker at least once. Make the job safe to run again: look for what it already made (firstOrCreate, a unique index), or record that it sent before it sends, and take that record back when the send fails. Give an outside call an Idempotency-Key header with the same value on each run.',
            AppFaults::finding($twice),
        );

        // Each finding the owner reads has a fix to give the coder.
        foreach (AppFaults::OWNED as $kind) {
            $this->assertMatchesRegularExpression('/\(caused in .+\)\. \w.+\.$/', AppFaults::finding([...$kept, 'kind' => $kind]));
        }
    }
}
