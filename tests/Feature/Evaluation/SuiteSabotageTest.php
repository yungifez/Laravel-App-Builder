<?php

namespace Tests\Feature\Evaluation;

use App\Evaluation\Suite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A defect planted inside the code an arm wrote needs its own patch per
 * arm; a suite-wide defect uses one patch for every arm.
 */
class SuiteSabotageTest extends TestCase
{
    protected Suite $suite;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = sys_get_temp_dir().'/builder-eval-suite-test-'.Str::lower(Str::random(8));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        File::ensureDirectoryExists("{$directory}/sabotage/in-change");
        File::put("{$directory}/sabotage/s1.patch", 'suite-wide');
        File::put("{$directory}/sabotage/in-change/i1.pipeline.patch", 'pipeline code');
        File::put("{$directory}/sabotage/in-change/i1.plain.patch", 'plain code');

        $this->suite = new Suite($directory, [
            'name' => 'test',
            'tasks' => [],
            'sabotage' => [
                ['key' => 's1', 'patch' => 's1.patch', 'area' => 'teams', 'covered_by_tests' => true, 'description' => '', 'honest_report' => ''],
            ],
            'in_change' => [
                ['key' => 'i1', 'task' => 'leave-team', 'patches' => ['pipeline' => 'in-change/i1.pipeline.patch', 'plain' => 'in-change/i1.plain.patch'], 'area' => 'teams', 'covered_by_tests' => false, 'description' => '', 'honest_report' => ''],
                ['key' => 'i2', 'task' => 'delete-team', 'patches' => ['pipeline' => 'in-change/i1.pipeline.patch'], 'area' => 'teams', 'covered_by_tests' => false, 'description' => '', 'honest_report' => ''],
            ],
        ]);
    }

    public function test_each_arm_gets_its_own_patch_for_a_defect_inside_its_change(): void
    {
        $sabotage = $this->suite->sabotageFor('leave-team');

        $this->assertSame(['s1', 'i1'], array_column($sabotage, 'key'));
        $this->assertSame('pipeline code', $this->suite->sabotagePatchFor($sabotage[1], 'pipeline'));
        $this->assertSame('plain code', $this->suite->sabotagePatchFor($sabotage[1], 'plain'));
    }

    public function test_a_suite_wide_defect_uses_one_patch_for_every_arm(): void
    {
        $sabotage = $this->suite->sabotageFor('delete-team')[0];

        $this->assertSame('suite-wide', $this->suite->sabotagePatchFor($sabotage, 'pipeline'));
        $this->assertSame('suite-wide', $this->suite->sabotagePatchFor($sabotage, 'plain'));
    }

    public function test_an_arm_whose_code_has_no_place_for_the_defect_gets_no_patch(): void
    {
        $sabotage = $this->suite->sabotageFor('delete-team');

        $this->assertSame(['s1', 'i2'], array_column($sabotage, 'key'));
        $this->assertNull($this->suite->sabotagePatchFor($sabotage[1], 'plain'));
    }
}
