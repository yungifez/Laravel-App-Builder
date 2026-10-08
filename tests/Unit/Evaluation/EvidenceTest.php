<?php

namespace Tests\Unit\Evaluation;

use App\Evaluation\Evidence;
use PHPUnit\Framework\TestCase;

class EvidenceTest extends TestCase
{
    public function test_it_reads_passing_failing_and_erroring_summaries()
    {
        $this->assertSame(['tests' => 5, 'failures' => 0], Evidence::counts("…\nOK (5 tests, 12 assertions)\n"));
        $this->assertSame(['tests' => 5, 'failures' => 1], Evidence::counts("FAILURES!\nTests: 5, Assertions: 5, Failures: 1.\n"));
        $this->assertSame(['tests' => 6, 'failures' => 6], Evidence::counts("ERRORS!\nTests: 6, Assertions: 0, Errors: 6.\n"));
        $this->assertSame(['tests' => 4, 'failures' => 3], Evidence::counts("ERRORS!\nTests: 4, Assertions: 2, Errors: 2, Failures: 1.\n"));
        $this->assertSame(['tests' => null, 'failures' => null], Evidence::counts('Fatal error'));
    }
}
