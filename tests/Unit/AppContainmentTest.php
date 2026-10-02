<?php

namespace Tests\Unit;

use App\Features\AppContainment;
use Tests\TestCase;

class AppContainmentTest extends TestCase
{
    /**
     * A patch that adds lines 3 and 4 to the checkout controller.
     */
    protected const PATCH = <<<'DIFF'
        diff --git a/app/Http/Controllers/CheckoutController.php b/app/Http/Controllers/CheckoutController.php
        --- a/app/Http/Controllers/CheckoutController.php
        +++ b/app/Http/Controllers/CheckoutController.php
        @@ -1,2 +1,4 @@
         <?php
         // Checkout
        +Http::post('https://api.stripe.com/v1/charges');
        +Http::get('https://maps.example.com');
        DIFF;

    protected const NEW = 'app/Http/Controllers/CheckoutController.php';

    /**
     * The areas a file belongs to: billing code is in Billing.
     *
     * @return list<string>
     */
    protected function areas(string $path): array
    {
        return str_starts_with($path, 'app/Billing/') ? ['Billing'] : [];
    }

    /**
     * One recorded request with outside calls from the given lines.
     *
     * @param  array<string, string>  $calls  The service called, by the line it was called from
     * @return array<string, mixed>
     */
    protected function recorded(array $calls): array
    {
        return ['test' => 'Tests\Feature\CheckoutTest::test_people_pay', 'method' => 'POST', 'route' => '/checkout', 'status' => 200, 'refused' => false, 'blind' => [], 'cut' => false, 'effects' => array_map(
            fn (string $at, string $what) => ['kind' => 'http', 'what' => $what, 'open' => 0, 'at' => $at, 'frames' => ['App\Http\Controllers\CheckoutController::store']],
            array_keys($calls),
            $calls,
        )];
    }

    public function test_a_service_the_app_calls_only_from_one_area_is_kept_there()
    {
        $measured = AppContainment::measure([$this->recorded([
            'app/Billing/StripeGateway.php:31' => 'POST api.stripe.com',
            self::NEW.':3' => 'POST api.stripe.com',
        ])], self::PATCH, $this->areas(...));

        $this->assertSame(['services' => 1, 'findings' => [
            ['route' => 'POST /checkout', 'what' => 'http POST api.stripe.com', 'at' => self::NEW.':3', 'in' => 'App\Http\Controllers\CheckoutController::store', 'from' => [], 'home' => ['Billing'], 'test' => 'Tests\Feature\CheckoutTest::test_people_pay'],
        ]], $measured);
    }

    public function test_a_call_from_inside_the_area_a_new_service_and_a_service_already_called_from_anywhere_are_left_alone()
    {
        // The change's own call from the billing area is where the app keeps it.
        $inside = AppContainment::measure([$this->recorded([
            'app/Billing/StripeGateway.php:31' => 'POST api.stripe.com',
            'app/Billing/Refunds.php:8' => 'POST api.stripe.com',
        ])], self::PATCH."\n".str_replace('Http/Controllers/CheckoutController', 'Billing/Refunds', self::PATCH), $this->areas(...));
        $this->assertSame([], $inside['findings']);

        // A service the app never called before has no place yet.
        $this->assertSame([], AppContainment::measure([$this->recorded([
            'app/Billing/StripeGateway.php:31' => 'POST api.stripe.com',
            self::NEW.':4' => 'GET maps.example.com',
        ])], self::PATCH, $this->areas(...))['findings']);

        // A service the app already calls from code no area claims is kept nowhere.
        $this->assertNull(AppContainment::measure([$this->recorded([
            'app/Billing/StripeGateway.php:31' => 'POST api.stripe.com',
            'app/Http/Controllers/RefundController.php:9' => 'POST api.stripe.com',
            self::NEW.':3' => 'POST api.stripe.com',
        ])], self::PATCH, $this->areas(...)));

        $this->assertNull(AppContainment::measure([], self::PATCH, $this->areas(...)));
    }
}
