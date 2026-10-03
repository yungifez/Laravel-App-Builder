<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairFailuresTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_security_lookup_that_could_not_run_is_not_handed_back_to_fix()
    {
        $verification = Verification::factory()->create(['results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'output' => 'Expected the button.', 'timed_out' => false],
            // No lock file: the change cannot fix this, and must not add one.
            ['name' => 'JavaScript packages', 'stage' => 'security', 'outcome' => 'errored', 'output' => 'npm error code ENOLOCK', 'timed_out' => false],
            ['name' => 'PHP packages', 'stage' => 'security', 'outcome' => 'failed', 'output' => 'Known problems: 1', 'timed_out' => false],
        ]]);

        $failures = app(CompleteRunVerification::class)->failures($verification);

        $this->assertStringNotContainsString('ENOLOCK', implode("\n", $failures));
        $this->assertCount(2, $failures);
    }
}
