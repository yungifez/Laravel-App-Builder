<?php

namespace Tests\Unit;

use App\Features\AppConventions;
use Tests\TestCase;

class AppConventionsTest extends TestCase
{
    /**
     * A patch that adds lines 3 and 4 to the invoice controller.
     */
    protected const PATCH = <<<'DIFF'
        diff --git a/app/Http/Controllers/InvoiceController.php b/app/Http/Controllers/InvoiceController.php
        --- a/app/Http/Controllers/InvoiceController.php
        +++ b/app/Http/Controllers/InvoiceController.php
        @@ -1,2 +1,4 @@
         <?php
         // Invoices
        +Invoice::create($data);
        +Mail::to($user)->send(new InvoiceMail);
        DIFF;

    /**
     * A request whose saves and sends came from the given code.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $effects  Each as [kind, at, nearest app code]
     * @return array{test: string|null, method: string, route: string|null, effects: list<array{kind: string, sql?: string, at: string, frames: list<string>}>}
     */
    protected function request(array $effects): array
    {
        return ['test' => 'Tests\Feature\InvoiceTest::test_people_send_invoices', 'method' => 'POST', 'route' => '/invoices', 'effects' => array_map(fn (array $effect) => [
            'kind' => $effect[0] === 'save' ? 'query' : $effect[0],
            ...($effect[0] === 'save' ? ['sql' => 'insert into "invoices" values (?)'] : []),
            'at' => $effect[1],
            'frames' => [$effect[2]],
        ], $effects)];
    }

    /**
     * Saves from five Action classes and one controller, and the new code.
     *
     * @return list<array{test: string|null, method: string, route: string|null, effects: list<array{kind: string, sql?: string, at: string, frames: list<string>}>}>
     */
    protected function requests(): array
    {
        return [
            $this->request([
                ['save', 'app/Actions/CreateOrder.php:12', 'App\Actions\CreateOrder::handle'],
                ['save', 'app/Actions/CreateOrder.php:12', 'App\Actions\CreateOrder::handle'],
                ['save', 'app/Actions/PayOrder.php:9', 'App\Actions\PayOrder::handle'],
                ['save', 'app/Billing/Actions/Refund.php:20', 'App\Billing\Actions\Refund::handle'],
                ['save', 'app/Actions/CloseOrder.php:7', 'App\Actions\CloseOrder::handle'],
                ['save', 'app/Actions/ShipOrder.php:7', 'App\Actions\ShipOrder::handle'],
                ['save', 'app/Http/Controllers/SessionController.php:30', 'App\Http\Controllers\SessionController::store'],
            ]),
            $this->request([
                ['save', 'app/Http/Controllers/InvoiceController.php:3', 'App\Http\Controllers\InvoiceController::store'],
                ['mail', 'app/Http/Controllers/InvoiceController.php:4', 'App\Http\Controllers\InvoiceController::store'],
            ]),
        ];
    }

    public function test_new_code_that_saves_straight_from_a_controller_bypasses_the_apps_actions()
    {
        $measured = AppConventions::measure($this->requests(), self::PATCH);

        // A loop that saves twice from one line is one place; the app has no habit for its sends.
        $this->assertSame(['save' => ['role' => 'action', 'places' => 5, 'of' => 6]], $measured['conventions']);
        $this->assertSame([
            ['work' => 'save', 'role' => 'controller', 'route' => 'POST /invoices', 'at' => 'app/Http/Controllers/InvoiceController.php:3', 'in' => 'App\Http\Controllers\InvoiceController::store', 'test' => 'Tests\Feature\InvoiceTest::test_people_send_invoices'],
        ], $measured['findings']);
        $this->assertSame('POST /invoices: a save at app/Http/Controllers/InvoiceController.php:3 in App\Http\Controllers\InvoiceController::store. Of the 6 saves seen in the rest of the app, 5 are in Action classes.', AppConventions::describe($measured['findings'][0], $measured['conventions']));
    }

    public function test_an_app_with_no_clear_habit_has_no_convention()
    {
        // Five of seven is not most of them.
        $requests = $this->requests();
        $requests[] = $this->request([['save', 'app/Http/Controllers/TeamController.php:8', 'App\Http\Controllers\TeamController::store']]);

        $this->assertNull(AppConventions::measure($requests, self::PATCH));
        // An app that saves from its controllers keeps that habit: no convention to bypass.
        $this->assertNull(AppConventions::measure([$this->request(array_map(fn (int $line) => ['save', "app/Http/Controllers/PostController.php:{$line}", 'App\Http\Controllers\PostController::store'], range(1, 6)))], self::PATCH));
    }

    public function test_each_class_is_named_by_the_folder_laravel_apps_keep_it_in()
    {
        $this->assertSame(
            ['controller', 'component', 'action', 'service', 'model', null],
            array_map(AppConventions::role(...), ['App\Http\Controllers\Admin\UserController::update', 'App\Livewire\Cart::add', 'App\Domain\Billing\Actions\Charge::handle', 'App\Services\Stripe::charge', 'App\Models\Order::markPaid', 'App\Support\Helpers::save']),
        );
    }
}
