<?php

namespace Tests\Unit;

use App\Features\AppDrift;
use Tests\TestCase;

class AppDriftTest extends TestCase
{
    /**
     * A request whose effects came from the given lines.
     *
     * @param  list<array{0: string, 1: string}>  $effects  Each as [kind, at]
     * @return array{effects: list<array{kind: string, at: string}>, cut: bool}
     */
    protected function request(array $effects, bool $cut = false): array
    {
        return ['effects' => array_map(fn (array $effect) => ['kind' => $effect[0], 'at' => $effect[1]], $effects), 'cut' => $cut];
    }

    /**
     * @return list<string>
     */
    protected function areas(string $path): array
    {
        return match (true) {
            str_starts_with($path, 'app/Billing/') => ['billing'],
            str_starts_with($path, 'app/Blog/') => ['blog'],
            default => [],
        };
    }

    public function test_the_work_per_request_is_counted_for_each_area_from_its_own_files()
    {
        $requests = [
            $this->request([['query', 'app/Billing/Invoices.php:10'], ['begin', 'app/Billing/Invoices.php:11'], ['query', 'app/Billing/Invoices.php:12'], ['http', 'app/Billing/Gateway.php:4'], ['commit', 'app/Billing/Invoices.php:13']]),
            $this->request([['query', 'app/Billing/Invoices.php:10'], ['query', 'app/Http/Controllers/HomeController.php:8']]),
            $this->request([['query', 'app/Billing/Invoices.php:10'], ['mail', 'app/Billing/Invoices.php:20']]),
            // A trace cut short says nothing about how much work was done.
            $this->request([['query', 'app/Billing/Invoices.php:10'], ['query', 'app/Billing/Invoices.php:10']], cut: true),
            // Too few requests for the blog to say anything.
            $this->request([['query', 'app/Blog/Posts.php:3']]),
        ];

        $this->assertSame(['billing' => ['requests' => 3, 'effects' => 6, 'per' => 2.0]], AppDrift::measure($requests, $this->areas(...)));
        $this->assertSame(['billing', 'blog'], array_keys(AppDrift::measure($requests, $this->areas(...), least: 1)));
    }

    public function test_an_area_is_found_when_its_work_grows_past_its_ceiling()
    {
        $measured = [
            'billing' => ['requests' => 4, 'effects' => 20, 'per' => 5.0],
            'blog' => ['requests' => 4, 'effects' => 10, 'per' => 2.5],
            'search' => ['requests' => 4, 'effects' => 40, 'per' => 10.0],
        ];

        // Billing grew a little, the blog grew far, and search has no ceiling yet.
        $this->assertSame([
            ['area' => 'billing', 'per' => 5.0, 'ceiling' => 3.8, 'far' => false],
            ['area' => 'blog', 'per' => 2.5, 'ceiling' => 1.0, 'far' => true],
        ], AppDrift::grown($measured, ['billing' => 3.8, 'blog' => 1], tolerance: 0.25, strict: 1.0));
        $this->assertSame([], AppDrift::grown($measured, ['billing' => 4.5, 'blog' => 2.2], tolerance: 0.25, strict: 1.0));
    }

    public function test_the_ceiling_moves_down_by_itself_and_up_only_when_the_owner_wants_it()
    {
        $measured = [
            'billing' => ['requests' => 4, 'effects' => 8, 'per' => 2.0],
            'blog' => ['requests' => 4, 'effects' => 40, 'per' => 10.0],
            'search' => ['requests' => 4, 'effects' => 12, 'per' => 3.0],
        ];
        $ceilings = ['billing' => 5.0, 'blog' => 4.0, 'orders' => 6.0];

        // Billing did less, the blog grew, search is new, orders was not measured.
        $this->assertSame(['billing' => 2.2, 'blog' => 4.0, 'orders' => 6.0, 'search' => 3.3], AppDrift::ratchet($measured, $ceilings, slack: 0.1));
        $this->assertSame(11.0, AppDrift::ratchet($measured, $ceilings, slack: 0.1, wanted: ['blog'])['blog']);
    }
}
