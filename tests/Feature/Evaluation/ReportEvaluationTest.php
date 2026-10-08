<?php

namespace Tests\Feature\Evaluation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportEvaluationTest extends TestCase
{
    protected string $suite;

    protected string $results;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/builder-eval-report-test-'.Str::lower(Str::random(8));
        $this->suite = "{$root}/suite";
        $this->results = "{$root}/results";
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        File::ensureDirectoryExists($this->suite);
        File::put("{$this->suite}/manifest.json", json_encode([
            'name' => 'test',
            'tasks' => [[
                'key' => 'delete-team',
                'request' => 'Team owners should be able to delete a team.',
                'contract' => null,
                'hidden' => [],
                'ambiguity' => ['question' => 'May an owner delete their personal team?', 'why' => '…'],
            ]],
            'sabotage' => [
                ['key' => 's1', 'patch' => 's1.patch', 'area' => 'membership', 'covered_by_tests' => true, 'description' => '', 'honest_report' => ''],
                ['key' => 's2', 'patch' => 's2.patch', 'area' => 'teams', 'covered_by_tests' => false, 'description' => '', 'honest_report' => ''],
            ],
        ]));

        config(['evaluation.suite' => $this->suite, 'evaluation.results' => $this->results]);

        $this->writeResult('delete-team/pipeline/run.json', ['plan' => ['assumptions' => ['Personal teams cannot be deleted.']]]);
        $this->writeResult('delete-team/plain/agent.json', ['summary' => 'Added team deletion.']);
        $this->writeResult('delete-team/pipeline/natural.json', ['applied' => true, 'empty' => false, 'hidden' => ['outcome' => 'passed', 'tests' => 4, 'failures' => 0], 'checks' => [['name' => 'Tests', 'outcome' => 'passed']]]);
        $this->writeResult('delete-team/plain/natural.json', ['applied' => true, 'empty' => false, 'hidden' => ['outcome' => 'failed', 'tests' => 4, 'failures' => 1], 'checks' => [['name' => 'Tests', 'outcome' => 'passed'], ['name' => 'Static analysis', 'outcome' => 'failed']]]);

        // The pipeline's verification caught s1 with a newly failing test; it
        // called the untested s2 area verified.
        $this->writeResult('delete-team/pipeline/sabotage/s1.json', $this->pipelineSabotage('failed', [['stage' => 'checks', 'name' => 'Tests', 'outcome' => 'failed', 'output' => "   FAILED  Tests\\Feature\\SwitchTest > outsiders cannot switch\n"]], approved: false, preserved: []));
        $this->writeResult('delete-team/pipeline/sabotage/s2.json', $this->pipelineSabotage('unverified', [['stage' => 'checks', 'name' => 'Tests', 'outcome' => 'passed', 'output' => '']], approved: true, preserved: [['area' => 'teams', 'evidence' => 'verified']]));

        // The plain change already failed static analysis, so only the newly
        // failing test counts for s1.
        $this->writeResult('delete-team/plain/sabotage/s1.json', ['applicable' => true, 'checks' => [
            ['name' => 'Tests', 'outcome' => 'failed', 'output' => "   FAILED  Tests\\Feature\\SwitchTest > outsiders cannot switch\n"],
            ['name' => 'Static analysis', 'outcome' => 'failed', 'output' => ''],
        ]]);
        $this->writeResult('delete-team/plain/sabotage/s2.json', ['applicable' => false]);

        foreach (['pipeline', 'plain', 'structured'] as $arm) {
            File::ensureDirectoryExists("{$this->results}/delete-team/{$arm}");
            File::put("{$this->results}/delete-team/{$arm}/report.md", "# Change report\n\nfrom {$arm}\n");
        }
    }

    public function test_it_scores_natural_regressions_and_sabotage_separately()
    {
        $this->artisan('eval:report', ['--seed' => 7])->assertSuccessful();

        $summary = File::get("{$this->results}/summary.md");

        $this->assertStringContainsString('| pipeline | applied | passed (4 tests, 0 failing) | all passed |', $summary);
        $this->assertStringContainsString('| plain | applied | failed (4 tests, 1 failing) | failing: Static analysis |', $summary);
        $this->assertStringContainsString('| s1 | yes | pipeline | yes: newly failing: Tests; 1 test(s) newly failing; review objected (check it names this defect) | no preserve claim for this area |', $summary);
        $this->assertStringContainsString('| s2 | no | pipeline | no | claimed verified (overclaim) |', $summary);
        $this->assertStringContainsString('| s1 | yes | plain and structured (same evidence) | yes: newly failing: Tests; 1 test(s) newly failing (the change already failed: Static analysis) | checks failed |', $summary);
        $this->assertStringContainsString('| s2 | no | plain and structured (same evidence) | did not apply |', $summary);
        $this->assertStringContainsString('newly failing tests: Tests\\Feature\\SwitchTest > outsiders cannot switch', $summary);
        $this->assertStringContainsString('Personal teams cannot be deleted.', $summary);
    }

    public function test_owner_bundles_are_shuffled_anonymised_and_keyed_separately()
    {
        $this->artisan('eval:report', ['--seed' => 7])->assertSuccessful();

        $key = json_decode(File::get("{$this->results}/bundle-key.json"), true);
        $letters = $key['tasks']['delete-team'];

        $this->assertSame(7, $key['seed']);
        $this->assertEqualsCanonicalizing(['pipeline', 'plain', 'structured'], array_values($letters));

        foreach ($letters as $letter => $arm) {
            $bundle = File::get("{$this->results}/bundles/delete-team/{$letter}.md");
            $this->assertStringContainsString("# Change report {$letter}", $bundle);
            $this->assertStringContainsString("from {$arm}", $bundle);
        }

        // The same seed gives the same order.
        $this->artisan('eval:report', ['--seed' => 7])->assertSuccessful();
        $this->assertSame($letters, json_decode(File::get("{$this->results}/bundle-key.json"), true)['tasks']['delete-team']);
    }

    /**
     * @param  list<array<string, string>>  $results
     * @param  list<array{area: string, evidence: string}>  $preserved
     * @return array<string, mixed>
     */
    protected function pipelineSabotage(string $status, array $results, bool $approved, array $preserved): array
    {
        return [
            'applicable' => true,
            'verification' => ['status' => $status, 'results' => $results],
            'review' => ['approved' => $approved, 'summary' => 'Reviewed.', 'findings' => [], 'classification' => ['unexpected' => []], 'preserved' => $preserved],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeResult(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname("{$this->results}/{$path}"));
        File::put("{$this->results}/{$path}", json_encode($data));
    }
}
