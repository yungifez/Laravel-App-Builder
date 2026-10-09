<?php

use App\Features\TestRefusals;

function refusalTrace(string $test, int $status, bool $refused = false, array $effects = []): array
{
    return ['test' => $test, 'method' => 'POST', 'route' => '/invoices', 'status' => $status, 'refused' => $refused, 'effects' => $effects];
}

it('names a test the same from its report, its trace and the reviewer', function () {
    $key = TestRefusals::key('tests/Feature/InvoiceTest.php', 'guests cannot see invoices');

    expect(TestRefusals::key('Tests\Feature\InvoiceTest', 'test_guests_cannot_see_invoices'))->toBe($key)
        ->and(TestRefusals::key('P\Tests\Feature\InvoiceTest', '__pest_evaluable_it_guests_cannot_see_invoices'))->toBe($key)
        ->and(TestRefusals::key('/workspace/tests/Feature/InvoiceTest.php', 'it guests cannot see invoices with data set "a"'))->toBe($key)
        ->and(TestRefusals::key('tests/Feature/OtherTest.php', 'guests cannot see invoices'))->not->toBe($key);
});

it('sees a refusal in an error answer, sent-back input or a redirect that saved nothing', function () {
    $patch = "diff --git a/tests/Feature/InvoiceTest.php b/tests/Feature/InvoiceTest.php\n--- /dev/null\n+++ b/tests/Feature/InvoiceTest.php\n@@ -0,0 +1 @@\n+test\n";
    $insert = ['kind' => 'query', 'open' => 0, 'sql' => 'insert into "invoices" ("total") values (?)'];
    $select = ['kind' => 'query', 'open' => 0, 'sql' => 'select * from "users"'];

    $seen = TestRefusals::measure([
        refusalTrace('Tests\Feature\InvoiceTest::test_forbidden', 403),
        refusalTrace('Tests\Feature\InvoiceTest::test_invalid', 302, refused: true),
        refusalTrace('Tests\Feature\InvoiceTest::test_to_sign_in', 302, effects: [$select]),
        refusalTrace('Tests\Feature\InvoiceTest::test_saved_then_redirected', 302, effects: [$insert]),
        refusalTrace('Tests\Feature\InvoiceTest::test_rolled_back', 302, effects: [['kind' => 'begin', 'open' => 0], $insert, ['kind' => 'rollback', 'open' => 1]]),
        refusalTrace('Tests\Feature\InvoiceTest::test_mailed_then_redirected', 302, effects: [['kind' => 'mail', 'open' => 0]]),
        refusalTrace('Tests\Feature\InvoiceTest::test_allowed', 200),
        refusalTrace('Tests\Feature\InvoiceTest::test_allowed', 403),
        refusalTrace('Tests\Feature\UntouchedTest::test_forbidden', 403),
        refusalTrace('Tests\Feature\InvoiceTest::test_unrelated', 200),
        ['test' => null, 'method' => 'GET', 'route' => '/', 'status' => 403, 'refused' => true, 'effects' => []],
    ], $patch);

    expect($seen)->toBe([
        'invoicetest|forbidden' => true,
        'invoicetest|invalid' => true,
        'invoicetest|to_sign_in' => true,
        'invoicetest|saved_then_redirected' => false,
        'invoicetest|rolled_back' => true,
        'invoicetest|mailed_then_redirected' => false,
        'invoicetest|allowed' => true,
        'invoicetest|unrelated' => false,
    ]);
});

it('says nothing when nothing was recorded', function () {
    expect(TestRefusals::measure([], 'diff'))->toBeNull();
});
