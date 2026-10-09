<?php

namespace Tests\Unit;

use App\Features\AppCoupling;
use Tests\TestCase;

class AppCouplingTest extends TestCase
{
    /**
     * A patch that changes the order controller.
     */
    protected const PATCH = <<<'DIFF'
        diff --git a/app/Http/Controllers/OrderController.php b/app/Http/Controllers/OrderController.php
        --- a/app/Http/Controllers/OrderController.php
        +++ b/app/Http/Controllers/OrderController.php
        @@ -1,2 +1,3 @@
         <?php
         // Orders
        +app(SendInvoice::class)->handle($order);
        DIFF;

    /**
     * A request whose one query came through the given chain, nearest first.
     *
     * @param  list<string>  $frames
     * @return array{test: string|null, method: string, route: string|null, effects: list<array{kind: string, frames: list<string>}>}
     */
    protected function request(string $route, array $frames): array
    {
        return ['test' => null, 'method' => 'POST', 'route' => $route, 'effects' => [['kind' => 'query', 'frames' => $frames]]];
    }

    /**
     * @return list<string>
     */
    protected function areas(string $path): array
    {
        return match (true) {
            str_starts_with($path, 'app/Billing/') => ['Billing'],
            $path === 'app/Http/Controllers/OrderController.php', str_starts_with($path, 'app/Orders/') => ['Orders'],
            $path === 'app/Http/Controllers/CheckoutController.php' => ['Checkout'],
            default => [],
        };
    }

    public function test_a_call_from_one_area_into_another_that_only_the_change_makes_is_found()
    {
        $measured = AppCoupling::measure([
            // Checkout already calls Billing; the change's chain does too.
            $this->request('/checkout', ['App\Billing\Charge::handle', 'App\Http\Controllers\CheckoutController::store']),
            $this->request('/orders', ['App\Billing\SendInvoice::handle', 'App\Http\Controllers\OrderController::store']),
            $this->request('/orders', ['App\Orders\Total::of', 'App\Http\Controllers\OrderController::store']),
        ], self::PATCH, $this->areas(...));

        $this->assertSame(['known' => 1, 'findings' => [
            ['from' => 'Orders', 'to' => 'Billing', 'caller' => 'App\Http\Controllers\OrderController::store', 'callee' => 'App\Billing\SendInvoice::handle', 'route' => 'POST /orders', 'test' => null],
        ]], $measured);
        $this->assertSame('Orders now calls into Billing: App\Http\Controllers\OrderController::store calls App\Billing\SendInvoice::handle (POST /orders)', AppCoupling::describe($measured['findings'][0]));
    }

    public function test_a_call_the_rest_of_the_app_already_makes_is_not_new()
    {
        $measured = AppCoupling::measure([
            $this->request('/orders/{order}', ['App\Billing\Charge::handle', 'App\Orders\Pay::handle']),
            $this->request('/orders', ['App\Billing\SendInvoice::handle', 'App\Http\Controllers\OrderController::store']),
        ], self::PATCH, $this->areas(...));

        $this->assertSame(['known' => 1, 'findings' => []], $measured);
        // A chain that never leaves one area says nothing.
        $this->assertNull(AppCoupling::measure([$this->request('/orders', ['App\Orders\Total::of', 'App\Http\Controllers\OrderController::store'])], self::PATCH, $this->areas(...)));
    }
}
