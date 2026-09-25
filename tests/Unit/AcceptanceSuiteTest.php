<?php

namespace Tests\Unit;

use App\Features\AcceptanceSuite;
use RuntimeException;
use Tests\Concerns\UsesAcceptanceSuite;
use Tests\TestCase;

class AcceptanceSuiteTest extends TestCase
{
    use UsesAcceptanceSuite;

    public function test_it_collects_the_runner_helpers_and_selected_tests()
    {
        $this->useAcceptanceSuite();

        $this->assertSame(
            ['tests/Acceptance/phpunit.xml', 'tests/Acceptance/Support/Helper.php', 'tests/Acceptance/Invitations/ContractTest.php'],
            array_keys(AcceptanceSuite::fromConfig()->files(['Invitations/ContractTest.php'])),
        );
    }

    public function test_paths_cannot_leave_the_suite_directory()
    {
        $this->useAcceptanceSuite();

        $this->expectException(RuntimeException::class);

        AcceptanceSuite::fromConfig()->files(['../secrets.php']);
    }

    public function test_an_unconfigured_suite_is_reported()
    {
        config(['builder.verification.acceptance.path' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BUILDER_ACCEPTANCE_PATH');

        AcceptanceSuite::fromConfig()->files(['Invitations/ContractTest.php']);
    }
}
