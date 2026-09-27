<?php

namespace Tests\Feature\Context;

use App\Actions\Context\ClassifyChange;
use App\Actions\Context\ObserveEffects;
use App\Context\Capability;
use App\Context\ProjectContext;
use App\Enums\EffectStrength;
use App\Enums\VerificationStatus;
use App\Features\TestMap;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\TestObservation;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\UsesAcceptanceSuite;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class TestImpactTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase, UsesAcceptanceSuite;

    /**
     * What the test map command writes: where the suite ran, the coverage
     * report's source, then each covered file and the tests that ran it.
     */
    protected const COVERAGE = <<<'TXT'
        /work/app-1
        <project source="/work/app-1/app"
        <file name="TeamPolicy.php" path="/Policies"
        covered by="Tests\Feature\TeamTest::test_owners_rename_teams"
        covered by="Tests\Feature\BillingTest::test_seats_follow_members"
        covered by="Tests\Feature\BillingTest::test_seats_follow_members with data set &quot;two&quot;"
        covered by="Tests\Feature\BillingTest::test_invoices_list_seats"
        <file name="Invoice.php" path="/Models"
        covered by="Tests\Feature\BillingTest::test_invoices_list_seats"
        covered by="P\Tests\Feature\ReportsTest::__pest_evaluable_it_totals_invoices"
        TXT;

    protected const LISTING = <<<'XML'
        <?xml version="1.0"?>
        <testSuite xmlns="https://xml.phpunit.de/testSuite">
         <tests>
          <testClass name="Tests\Feature\TeamTest" file="/work/app-1/tests/Feature/TeamTest.php">
           <testMethod id="Tests\Feature\TeamTest::test_owners_rename_teams" name="test_owners_rename_teams"/>
          </testClass>
          <testClass name="Tests\Feature\BillingTest" file="/work/app-1/tests/Feature/BillingTest.php">
           <testMethod id="Tests\Feature\BillingTest::test_seats_follow_members" name="test_seats_follow_members"/>
           <testMethod id="Tests\Feature\BillingTest::test_invoices_list_seats" name="test_invoices_list_seats"/>
          </testClass>
         </tests>
         <groups>
          <group name="behavior:rename-team">
           <test id="Tests\Feature\TeamTest::test_owners_rename_teams"/>
          </group>
          <group name="behavior:seat-billing">
           <test id="Tests\Feature\BillingTest::test_seats_follow_members"/>
          </group>
         </groups>
        </testSuite>
        XML;

    protected function context(): ProjectContext
    {
        return new ProjectContext(capabilities: [
            'billing' => new Capability('billing', 'Billing', paths: ['app/Models/Invoice.php', 'tests/Feature/BillingTest.php'], behaviors: [['key' => 'seat-billing', 'name' => 'Bill for seats']]),
            'reports' => new Capability('reports', 'Reports', paths: ['tests/Feature/ReportsTest.php']),
            'teams' => new Capability('teams', 'Teams', paths: ['app/Policies/**'], behaviors: [['key' => 'rename-team', 'name' => 'Rename a team']]),
        ]);
    }

    protected function observe(Project $project, ?FeatureRequest $change = null): TestObservation
    {
        $map = TestMap::parse(self::COVERAGE, self::LISTING);

        return TestObservation::create(['project_id' => $project->id, 'feature_request_id' => $change?->id, 'tests' => $map->tests, 'files' => $map->files]);
    }

    public function test_the_map_reads_which_tests_ran_which_files_and_the_behaviours_they_prove()
    {
        $map = TestMap::parse(self::COVERAGE, self::LISTING);

        $this->assertSame(['app/Policies/TeamPolicy.php', 'app/Models/Invoice.php'], array_keys($map->files));
        $this->assertSame([
            ['id' => 'Tests\Feature\TeamTest::test_owners_rename_teams', 'file' => 'tests/Feature/TeamTest.php', 'groups' => ['behavior:rename-team']],
            ['id' => 'Tests\Feature\BillingTest::test_seats_follow_members', 'file' => 'tests/Feature/BillingTest.php', 'groups' => ['behavior:seat-billing']],
            ['id' => 'Tests\Feature\BillingTest::test_invoices_list_seats', 'file' => 'tests/Feature/BillingTest.php', 'groups' => []],
            // Left out of the list, so its file is read from its class name.
            ['id' => 'P\Tests\Feature\ReportsTest::__pest_evaluable_it_totals_invoices', 'file' => 'tests/Feature/ReportsTest.php', 'groups' => []],
        ], $map->tests);
        // Each data set of a test counts as that one test.
        $this->assertSame([0, 1, 2], $map->testsRunning(['app/Policies/TeamPolicy.php']));
        $this->assertSame(['rename-team', 'seat-billing'], $map->provenBehaviors());
    }

    public function test_tests_of_one_area_running_another_areas_code_become_observed_effects()
    {
        $project = Project::factory()->create();
        $this->observe($project);

        $context = app(ObserveEffects::class)->handle($project, $this->context());

        [$billing] = $context->capabilities['teams']->effects;
        $this->assertSame('billing', $billing->to);
        $this->assertSame(EffectStrength::Strong, $billing->strength);
        $this->assertSame('tests', $billing->source);
        $this->assertSame('2 tests for Billing run this area\'s code (app/Policies/TeamPolicy.php).', $billing->reason);
        $this->assertSame(now()->format('Y-m-d'), $billing->observed);

        [$reports] = $context->capabilities['billing']->effects;
        $this->assertSame('reports', $reports->to);
        $this->assertSame(EffectStrength::Possible, $reports->strength);

        // Reports claims no code; nothing is observed about it, and nobody
        // relates to itself.
        $this->assertSame([], $context->capabilities['reports']->effects);
    }

    public function test_without_a_test_map_the_context_is_as_the_notes_say()
    {
        $project = Project::factory()->create();
        TestObservation::create(['project_id' => $project->id, 'tests' => [], 'files' => [], 'error' => 'No code coverage was recorded.']);

        $context = app(ObserveEffects::class)->handle($project, $this->context());

        $this->assertSame([], $context->capabilities['teams']->effects);
    }

    public function test_the_map_of_a_kept_change_wins_over_a_newer_one_nobody_kept()
    {
        $project = Project::factory()->create();
        $kept = $this->observe($project, FeatureRequest::factory()->for($project)->create(['accepted_at' => now()]));
        $this->observe($project, FeatureRequest::factory()->for($project)->create());

        $this->assertTrue(TestObservation::latestFor($project)->is($kept));
    }

    public function test_a_change_is_told_which_areas_tests_ran_its_code_and_which_code_no_test_ran()
    {
        $patch = implode("\n", [
            'diff --git a/app/Policies/TeamPolicy.php b/app/Policies/TeamPolicy.php',
            '--- a/app/Policies/TeamPolicy.php',
            '+++ b/app/Policies/TeamPolicy.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// changed',
            'diff --git a/app/Support/Money.php b/app/Support/Money.php',
            '--- a/app/Support/Money.php',
            '+++ b/app/Support/Money.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// changed',
            '',
        ]);

        $classification = app(ClassifyChange::class)->handle($this->context(), ['teams'], $patch, map: TestMap::parse(self::COVERAGE, self::LISTING));

        $this->assertSame(['areas' => ['billing' => 2, 'teams' => 1], 'tests' => 3, 'unmapped' => ['app/Support/Money.php']], $classification->observed);
        $this->assertNull(app(ClassifyChange::class)->handle($this->context(), ['teams'], $patch)->observed);
    }

    public function test_checking_a_change_keeps_the_map_its_passing_suite_showed_before_the_protected_tests_run()
    {
        $driver = $this->checks(suitePasses: true);
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $observation = TestObservation::sole();
        $this->assertSame($verification->id, $observation->verification_id);
        $this->assertSame($request->project_id, $observation->project_id);
        $this->assertNull($observation->error);
        $this->assertSame(['app/Policies/TeamPolicy.php', 'app/Models/Invoice.php'], array_keys($observation->files));

        $commands = array_column($driver->executed, 'command');
        $map = array_search(['sh', '-c', 'make the test map'], $commands, true);
        $this->assertLessThan(array_search(['rm', '-rf', 'tests/Acceptance'], $commands, true), $map);
    }

    public function test_no_map_is_made_from_a_failing_suite()
    {
        $this->checks(suitePasses: false);
        $failing = FeatureRequest::factory()->generated()->create();

        $this->actingAs($failing->project->owner)->post(route('feature-requests.verifications.store', $failing));

        $this->assertSame(VerificationStatus::Failed, $failing->verifications()->sole()->status);
        $this->assertSame(0, TestObservation::count());
    }

    public function test_a_map_that_fails_is_recorded_and_never_changes_the_result()
    {
        $this->checks(suitePasses: true, mapWorks: false);
        $passing = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        $this->actingAs($passing->project->owner)->post(route('feature-requests.verifications.store', $passing));

        $this->assertSame(VerificationStatus::Passed, $passing->verifications()->sole()->status);
        $this->assertSame('The tests could not run with code coverage.', TestObservation::sole()->error);
    }

    public function test_the_report_compares_what_tests_reached_with_what_the_change_touched()
    {
        $change = FeatureRequest::factory()->generated()->create();
        Run::factory()->for($change)->create([
            'review' => ['approved' => true, 'summary' => '', 'findings' => [], 'changes' => [], 'classification' => [
                'requested' => ['teams' => ['app/Policies/TeamPolicy.php']],
                'may_also_affect' => [],
                'unexpected' => ['settings' => ['config/teams.php']],
                'unclaimed' => [],
                'context_updates' => [],
                'targets' => ['teams'],
                'observed' => ['areas' => ['billing' => 2, 'teams' => 1], 'tests' => 3, 'unmapped' => ['app/Support/Money.php']],
            ]],
        ]);

        $this->artisan('builder:effects')
            ->expectsTable(
                ['Change', 'Asked about', 'Tests reached', 'Areas reached', 'Touched outside the ask', 'Missed', 'Unknown files'],
                [["#{$change->id}", 'teams', 3, 'billing', 'settings', 'settings', 1]],
            )
            ->assertSuccessful();
    }

    /**
     * Fake a box whose suite passes or fails, and whose test map command
     * writes the reports above, or fails.
     */
    protected function checks(bool $suitePasses, bool $mapWorks = true): FakeWorkspaceDriver
    {
        $driver = $this->fakeWorkspaces();
        $this->useAcceptanceSuite();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300]],
            'builder.verification.test_map' => [
                'enabled' => true,
                'command' => ['sh', '-c', 'make the test map'],
                'timeout' => 900,
                'report' => 'covered.txt',
                'listing' => 'tests.xml',
            ],
        ]);

        $driver->onExec = function (string $id, array $command) use ($driver, $suitePasses, $mapWorks) {
            $map = $command === ['sh', '-c', 'make the test map'];

            if ($map && $mapWorks) {
                $driver->files["{$id}:covered.txt"] = self::COVERAGE;
                $driver->files["{$id}:tests.xml"] = self::LISTING;
            }

            $passed = $map ? $mapWorks : ($command !== ['php', 'artisan', 'test'] || $suitePasses);

            return new CommandResult(exitCode: $passed ? 0 : 1, output: 'ok', errorOutput: '', durationMs: 5);
        };

        return $driver;
    }
}
