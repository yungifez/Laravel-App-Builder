<?php

namespace Tests\Feature\Evaluation;

use App\Evaluation\Suite;
use App\Runs\Plan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class DryRunResponderTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/builder-dry-respond-test-'.Str::lower(Str::random(8));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->directory));

        config([
            'evaluation.suite' => 'fixtures/evaluation/customer-app/comparison',
            'evaluation.handoff.path' => $this->directory,
        ]);
    }

    public function test_its_plan_is_one_the_pipeline_accepts()
    {
        $task = Suite::fromConfig()->task('transfer-ownership');

        $plan = Plan::fromModelOutput($this->answer('planner', "## Request\n\n{$task['request']}"), []);

        $this->assertSame($task['areas'] ?? [], $plan->capabilities);
        $this->assertNotEmpty($plan->steps);
    }

    public function test_its_reviewers_reject_only_a_diff_touching_a_sabotaged_file()
    {
        $suite = Suite::fromConfig();
        $sabotaged = $suite->sabotagePatch($suite->sabotage()[0]['patch']);

        $this->assertTrue($this->answer('generic-reviewer', "diff --git a/README.md b/README.md\n")['approved']);
        $this->assertFalse($this->answer('generic-reviewer', $sabotaged)['approved']);
        $this->assertSame([], $this->answer('reviewer', 'No diff.')['changes']);
    }

    /**
     * Write one hand-off request, let the responder answer it, and read the answer.
     *
     * @return array<string, mixed>
     */
    protected function answer(string $role, string $prompt): array
    {
        $id = Str::lower(Str::random(8));
        $response = "{$this->directory}/{$id}.response.json";

        File::ensureDirectoryExists($this->directory);
        File::put("{$this->directory}/{$id}.request.json", (string) json_encode(['id' => $id, 'role' => $role, 'response' => $response, 'prompt' => $prompt]));

        $this->artisan('eval:dry-respond', ['--once' => true])->assertSuccessful();

        $answer = json_decode(File::get($response), true);
        $this->assertIsArray($answer);

        /** @var array<string, mixed> $answer */
        return $answer;
    }
}
