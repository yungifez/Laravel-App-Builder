<?php

namespace Tests\Feature\Evaluation;

use App\Evaluation\Evidence;
use App\Evaluation\Workbench;
use App\Workspaces\CommandResult;
use Tests\TestCase;

/**
 * Scoring runs the project's checks as verification does: a check that
 * needs a file the project lacks says nothing about the change.
 */
class EvidenceChecksTest extends TestCase
{
    /**
     * @var list<list<string>>
     */
    protected array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.verification.checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 60],
            ['name' => 'Laravel structure', 'command' => ['sh', '-c', 'pest arch'], 'timeout' => 60, 'needs' => 'vendor/pestphp/pest/src/ArchPresets/Laravel.php'],
        ]]);
    }

    public function test_a_check_whose_file_the_project_has_is_run()
    {
        $checks = Evidence::checks($this->workbench(missing: []));

        $this->assertSame(['Tests', 'Laravel structure'], array_column($checks, 'name'));
        $this->assertSame(['passed', 'passed'], array_column($checks, 'outcome'));
        $this->assertContains(['sh', '-c', 'pest arch'], $this->ran);
    }

    public function test_a_check_whose_file_the_project_lacks_is_left_out_and_never_run()
    {
        $checks = Evidence::checks($this->workbench(missing: ['vendor/pestphp/pest/src/ArchPresets/Laravel.php']));

        $this->assertSame(['Tests'], array_column($checks, 'name'));
        $this->assertNotContains(['sh', '-c', 'pest arch'], $this->ran);
    }

    public function test_a_check_that_needs_nothing_still_fails_when_its_command_fails()
    {
        $checks = Evidence::checks($this->workbench(missing: ['vendor/pestphp/pest/src/ArchPresets/Laravel.php'], failing: ['php', 'artisan', 'test']));

        $this->assertSame([['Tests', 'failed']], array_map(fn (array $check) => [$check['name'], $check['outcome']], $checks));
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>|null  $failing
     */
    protected function workbench(array $missing, ?array $failing = null): Workbench
    {
        $workbench = $this->createMock(Workbench::class);
        $workbench->method('run')->willReturnCallback(function (array $command) use ($missing, $failing) {
            $this->ran[] = $command;
            $failed = ($command[0] === 'test' && in_array($command[2], $missing, true)) || $command === $failing;

            return new CommandResult(exitCode: $failed ? 1 : 0, output: '', errorOutput: '', durationMs: 1);
        });

        return $workbench;
    }
}
