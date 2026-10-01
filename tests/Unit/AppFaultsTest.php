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
     * A call to an outside service, from a line of the app's code.
     *
     * @return array<string, mixed>
     */
    protected function called(?string $at, string $method = 'POST', bool $keyed = false): array
    {
        return ['kind' => 'http', 'what' => "{$method} pay.example", 'open' => 0, 'at' => $at, ...($keyed ? ['keyed' => true] : [])];
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

        // With nothing suspected: the change's own, then each send, then the job, then the save.
        // The job is there twice. The change wrote what comes after it, so holding it back
        // until the response is the change's place. Running it a second time is not.
        $this->assertSame(['mail App\Mail\Receipt', 'job App\Jobs\SendReceipt', 'mail App\Mail\Told', 'http POST api.stripe.com', 'mail App\Mail\Welcome', 'job App\Jobs\SendReceipt', 'update products'], $order([]));
        // A finding on a route puts that route's places before the rest.
        $this->assertSame(['mail App\Mail\Receipt', 'job App\Jobs\SendReceipt', 'mail App\Mail\Welcome', 'mail App\Mail\Told', 'http POST api.stripe.com', 'job App\Jobs\SendReceipt', 'update products'], $order([['route' => 'POST /teams', 'at' => null]]));
        // A finding on a line does the same for the place on that line.
        $this->assertSame(['mail App\Mail\Receipt', 'job App\Jobs\SendReceipt', 'update products', 'mail App\Mail\Told', 'http POST api.stripe.com', 'mail App\Mail\Welcome', 'job App\Jobs\SendReceipt'], $order([['route' => 'GET /stock', 'at' => "{$old}/TakeStock.php:9"]]));
        // The same input gives the same order.
        $this->assertSame($order([['route' => 'POST /teams']]), $order([['route' => 'POST /teams']]));
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

        // In use the job runs later, on a queue: its email and its save are not the request's.
        // The job is a place by itself, and so is the save it makes after its email.
        $this->assertSame(['again', 'retry'], array_column($this->points([$this->recorded('POST', '/orders', 302, [
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

        // The change queues the job: the place is the change's.
        $this->assertSame([['again', 'job App\Jobs\SendReceipt', self::NEW.':4', true, 0, 'job']], $places([$this->job(self::NEW.':4'), $receipt]));
        // Code the app already had queues it: the place is tried after the change's own.
        $this->assertSame([['again', 'job App\Jobs\SendReceipt', 'app/Actions/PlaceOrder.php:9', false, 1, 'job']], $places([$ran, $this->job('app/Actions/PlaceOrder.php:9'), $receipt]));
        // The change wrote what the job does: the place is the change's too.
        $this->assertSame([true], array_column($places([$this->job('app/Actions/PlaceOrder.php:9'), $this->done($this->mailed(self::NEW.':4'))]), 3));

        // A job that only reads, or makes a change that can be made again, is no place.
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $this->done($this->asked('select * from "orders"', 'app/Jobs/SendReceipt.php:19')), $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:21'))]));
        // An insert that says what to do when the row is there is made to run twice.
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $this->done($this->asked('insert into "receipts" ("order_id") values (?) on conflict ("order_id") do nothing', 'app/Jobs/SendReceipt.php:20'))]));
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $this->done($this->asked('insert ignore into `receipts` (`order_id`) values (?)', 'app/Jobs/SendReceipt.php:20'))]));
        // An insert the job put back did not stay.
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $this->done(['kind' => 'begin', 'open' => 1]), [...$receipt, 'open' => 1], $this->done(['kind' => 'rollback', 'open' => 0])]));
        // A job of the framework that delivers one email has no code of the app to make safe.
        $this->assertSame([], $places([[...$this->job(self::NEW.':4'), 'delivers' => true], $this->done($this->mailed(null))]));
        // What a job inside the job does is the outer job's. The outer job queued it and then
        // saved: a second try queues it again, so that save is the outer job's second place.
        $this->assertSame([0, 2], array_column($places([$this->job(self::NEW.':4'), $this->done($this->job('app/Jobs/SendReceipt.php:18')), $receipt]), 4));
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
        // The change the job makes after its email is a second place, not tried here.
        $this->assertSame(['points' => 2, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key($measured, ['findings' => 1]));
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
            ['save', 'update products', self::NEW.':5', 1, 'query'],
        ], $places([$this->asked('insert into "orders" ("total") values (?)', self::NEW.':3'), $stock, $this->job(self::NEW.':4'), $asks, $mail, $marks, $logs]));
        // A job that saves first and sends last has no save to lose after the send.
        $this->assertSame(['again'], array_column($places([$this->job(self::NEW.':4'), $marks, $mail]), 0));
        // A job of the framework is no place.
        $this->assertSame([], $places([[...$this->job(self::NEW.':4'), 'delivers' => true], $mail, $marks]));
    }

    public function test_a_job_tried_again_after_its_save_failed_is_found_when_it_sent_the_same_thing_again()
    {
        $asks = $this->done($this->asked('select * from "orders" where "receipt_sent_at" is null', 'app/Jobs/SendReceipt.php:19'));
        $mail = $this->done($this->mailed('app/Jobs/SendReceipt.php:21'));
        $marks = $this->done($this->asked('update "orders" set "receipt_sent_at" = ?', 'app/Jobs/SendReceipt.php:22'));
        $normal = [$this->job(self::NEW.':4'), $asks, $mail, $marks];
        $again = $this->done([...$this->job(null), 'again' => true]);

        $measured = $this->measure($normal, 500, [...$normal, $again, $asks, $mail, $marks], point: 1);

        $this->assertSame(['points' => 2, 'run' => 1, 'missed' => 0, 'existing' => 0], array_diff_key((array) $measured, ['findings' => 1]));
        $this->assertSame([
            ['kind' => 'sent_again', 'route' => 'POST /orders', 'failed' => 'job App\Jobs\SendReceipt', 'what' => 'mail App\Mail\Receipt', 'at' => self::NEW.':4', 'test' => self::TEST],
        ], $measured['findings'] ?? null);
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

    public function test_a_job_the_request_does_more_after_is_a_place()
    {
        $job = $this->job('app/Http/Controllers/OrderController.php:1');
        $adds = $this->done($this->asked('insert into "invoices" ("order_id") values (?)', 'app/Jobs/SendReceipt.php:19'));
        $reads = $this->asked('select * from "invoices" where "order_id" = ?', self::NEW.':5');
        $places = fn (array $effects) => array_map(fn (array $point) => [$point['fails'], $point['fault']['effect'], $point['fault']['kind'], $point['own']], $this->points([$this->recorded('POST', '/orders', 302, $effects)]));

        // The change wrote what the request does after the job, so the place is the change's.
        $this->assertSame([['later', 0, 'later', true], ['again', 0, 'job', false]], $places([$job, $adds, $reads]));
        // Nothing of the app's code comes after the job, or the job did nothing that was seen.
        $this->assertSame([['again', 0, 'job', true]], $places([$this->job(self::NEW.':4'), $adds]));
        $this->assertSame([], $places([$this->job(self::NEW.':4'), $reads]));
        // What the framework does by itself after the job is not the app's.
        $this->assertSame([['again', 0, 'job', true]], $places([$this->job(self::NEW.':4'), $adds, $this->asked('update "sessions" set "payload" = ?', null)]));
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
}
