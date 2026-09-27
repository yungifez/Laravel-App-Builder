<?php

namespace Tests\Feature\Context;

use App\Actions\Context\ClassifyChange;
use App\Actions\Context\CompileContext;
use App\Actions\Context\ObserveEffects;
use App\Context\Capability;
use App\Context\ProjectContext;
use App\Enums\ContextMode;
use App\Enums\EffectStrength;
use App\Enums\VerificationStatus;
use App\Features\PatchSummary;
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

    /**
     * The same report with line numbers: the team policy's rules (lines
     * 10 to 12) run in three tests; its renaming (30 and 31) in one.
     */
    protected const LINE_COVERAGE = <<<'TXT'
        /work/app-1
        <project source="/work/app-1/app"
        <file name="TeamPolicy.php" path="/Policies"
        <line nr="10"
        covered by="Tests\Feature\TeamTest::test_owners_rename_teams"
        covered by="Tests\Feature\BillingTest::test_seats_follow_members"
        covered by="Tests\Feature\BillingTest::test_invoices_list_seats"
        <line nr="12"
        covered by="Tests\Feature\TeamTest::test_owners_rename_teams"
        covered by="Tests\Feature\BillingTest::test_seats_follow_members"
        covered by="Tests\Feature\BillingTest::test_invoices_list_seats"
        <line nr="30"
        covered by="Tests\Feature\TeamTest::test_owners_rename_teams"
        <line nr="31"
        covered by="Tests\Feature\TeamTest::test_owners_rename_teams"
        <file name="Invoice.php" path="/Models"
        <line nr="5"
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

    public function test_the_agent_is_told_which_existing_tests_run_the_code_of_the_area_it_changes()
    {
        $project = Project::factory()->create();
        $this->observe($project);

        $context = app(ObserveEffects::class)->handle($project, $this->context());

        // Most tests first; a tie goes by name.
        $this->assertSame(['tests/Feature/BillingTest.php', 'tests/Feature/TeamTest.php'], $context->capabilities['teams']->reachedBy);
        $this->assertSame(['tests/Feature/BillingTest.php', 'tests/Feature/ReportsTest.php'], $context->capabilities['billing']->reachedBy);
        $this->assertSame([], $context->capabilities['reports']->reachedBy);

        $pack = app(CompileContext::class)->handle($context, ['teams'], ContextMode::Selective);

        $this->assertStringContainsString("Existing tests for this area, most relevant first:\n- tests/Feature/BillingTest.php\n- tests/Feature/TeamTest.php", $pack->text);
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
            // Coverage measured nothing in these folders, so the map cannot
            // say whether a test ran them: unknown, not a gap.
            'diff --git a/config/teams.php b/config/teams.php',
            '--- a/config/teams.php',
            '+++ b/config/teams.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// changed',
            'diff --git a/database/migrations/2026_01_01_000000_add_seats.php b/database/migrations/2026_01_01_000000_add_seats.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/database/migrations/2026_01_01_000000_add_seats.php',
            '@@ -0,0 +1 @@',
            '+<?php',
            '',
        ]);

        $classification = app(ClassifyChange::class)->handle($this->context(), ['teams'], $patch, map: TestMap::parse(self::COVERAGE, self::LISTING));

        $this->assertSame(['areas' => ['billing' => 2, 'teams' => 1], 'tests' => 3, 'unmapped' => ['app/Support/Money.php'], 'foundation' => [], 'by_line' => []], $classification->observed);
        $this->assertNull(app(ClassifyChange::class)->handle($this->context(), ['teams'], $patch)->observed);
    }

    public function test_code_most_tests_run_ties_no_areas_and_makes_a_change_to_it_broad()
    {
        // Three of the four tests run the team policy: it is foundation.
        config(['builder.verification.test_map.foundation_min_tests' => 4]);
        $project = Project::factory()->create();
        $this->observe($project);

        $context = app(ObserveEffects::class)->handle($project, $this->context());

        $this->assertSame([], $context->capabilities['teams']->effects);
        $this->assertSame([], $context->capabilities['teams']->reachedBy);
        // Code only some tests run still ties its areas.
        $this->assertSame(['reports'], array_map(fn ($effect) => $effect->to, $context->capabilities['billing']->effects));

        $patch = implode("\n", [
            'diff --git a/app/Policies/TeamPolicy.php b/app/Policies/TeamPolicy.php',
            '--- a/app/Policies/TeamPolicy.php',
            '+++ b/app/Policies/TeamPolicy.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// changed',
            '',
        ]);

        $classification = app(ClassifyChange::class)->handle($this->context(), ['teams'], $patch, map: TestMap::parse(self::COVERAGE, self::LISTING));

        $this->assertSame(['areas' => [], 'tests' => 0, 'unmapped' => [], 'foundation' => ['app/Policies/TeamPolicy.php'], 'by_line' => []], $classification->observed);
    }

    public function test_a_small_suite_has_no_foundation()
    {
        $this->assertSame([], TestMap::parse(self::COVERAGE, self::LISTING)->foundation());
    }

    public function test_the_map_keeps_the_lines_each_test_ran_as_ranges()
    {
        $map = TestMap::parse(self::LINE_COVERAGE, self::LISTING);

        // Lines a short gap apart (a blank line, a comment) share a range.
        $this->assertSame([0 => [[10, 12], [30, 31]], 1 => [[10, 12]], 2 => [[10, 12]]], $map->lines['app/Policies/TeamPolicy.php']);
        $this->assertSame([0], $map->testsRunningLines('app/Policies/TeamPolicy.php', [30]));
        $this->assertSame([0, 1, 2], $map->testsRunningLines('app/Policies/TeamPolicy.php', [11]));
        // Lines no test ran say nothing; the caller goes by the whole file.
        $this->assertNull($map->testsRunningLines('app/Policies/TeamPolicy.php', [50]));
        $this->assertNull(TestMap::parse(self::COVERAGE, self::LISTING)->testsRunningLines('app/Policies/TeamPolicy.php', [30]));
    }

    public function test_a_diff_names_the_lines_it_changed_on_either_side()
    {
        $added = implode("\n", ['@@ -29,2 +29,3 @@', ' a', '+b', ' c']);
        $removed = implode("\n", ['@@ -5,3 +5,2 @@', ' a', '-b', ' c']);

        $this->assertSame([30], PatchSummary::changedLines($added));
        $this->assertSame([29, 30], PatchSummary::changedLines($added, after: false));
        $this->assertSame([5, 6], PatchSummary::changedLines($removed));
        $this->assertSame([6], PatchSummary::changedLines($removed, after: false));
    }

    public function test_a_change_reaches_the_tests_that_ran_its_lines_not_all_that_ran_its_file()
    {
        // The team policy is foundation as a whole file: three of four tests.
        config(['builder.verification.test_map.foundation_min_tests' => 4]);
        $patch = implode("\n", [
            'diff --git a/app/Policies/TeamPolicy.php b/app/Policies/TeamPolicy.php',
            '--- a/app/Policies/TeamPolicy.php',
            '+++ b/app/Policies/TeamPolicy.php',
            '@@ -29,2 +29,3 @@',
            ' a',
            '+b',
            ' c',
            'diff --git a/app/Models/Invoice.php b/app/Models/Invoice.php',
            '--- a/app/Models/Invoice.php',
            '+++ b/app/Models/Invoice.php',
            '@@ -39,1 +39,2 @@',
            ' a',
            '+b',
            '',
        ]);

        $classification = app(ClassifyChange::class)->handle($this->context(), ['teams'], $patch, map: TestMap::parse(self::LINE_COVERAGE, self::LISTING));

        // The renaming lines ran in one test only, so the change is narrow.
        // No test ran the new invoice line, so the whole file counts.
        $this->assertSame([
            'areas' => ['billing' => 1, 'reports' => 1, 'teams' => 1],
            'tests' => 3,
            'unmapped' => [],
            'foundation' => [],
            'by_line' => ['app/Policies/TeamPolicy.php'],
        ], $classification->observed);
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
                ['Change', 'Asked about', 'Tests reached', 'Areas reached', 'Touched outside the ask', 'Missed', 'Unknown files', 'Foundation files', 'Narrowed by line'],
                [["#{$change->id}", 'teams', 3, 'billing', 'settings', 'settings', 1, 0, 0]],
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

    public function test_kept_changes_that_moved_two_areas_together_become_history_effects()
    {
        $project = Project::factory()->create();
        $change = function (array $touched, array $state) use ($project) {
            $featureRequest = FeatureRequest::factory()->for($project)->create($state);
            Run::factory()->for($featureRequest)->create(['review' => ['approved' => true, 'summary' => '', 'findings' => [], 'changes' => [], 'classification' => [
                'requested' => ['teams' => ['app/Policies/TeamPolicy.php']],
                'may_also_affect' => [],
                'unexpected' => array_fill_keys($touched, ['app/Models/Invoice.php']),
                'unclaimed' => [],
                'context_updates' => [],
                'targets' => ['teams'],
            ]]]);
        };

        $change(['billing'], ['accepted_at' => '2026-09-01 10:00:00']);
        $change(['billing', 'reports'], ['accepted_at' => '2026-09-03 10:00:00']);
        // Neither a change still waiting nor one undone is evidence.
        $change(['reports'], []);
        $change(['reports'], ['accepted_at' => now(), 'reverted_at' => now()]);

        $context = app(ObserveEffects::class)->handle($project, $this->context());

        [$billing] = $context->capabilities['teams']->effects;
        $this->assertSame('billing', $billing->to);
        $this->assertSame(EffectStrength::Historical, $billing->strength);
        $this->assertSame('history', $billing->source);
        $this->assertSame('2 kept changes to this area also changed Billing.', $billing->reason);
        $this->assertSame('2026-09-03', $billing->observed);

        // One kept change is not a pattern, and the link runs from the area
        // the changes were about.
        $this->assertCount(1, $context->capabilities['teams']->effects);
        $this->assertSame([], $context->capabilities['billing']->effects);
    }

    public function test_a_test_says_what_it_checks_in_its_authors_words()
    {
        $map = TestMap::fromArray([
            ['id' => 'Tests\\Feature\\TeamTest::test_owners_can_rename_teams', 'file' => 'tests/Feature/TeamTest.php', 'groups' => []],
            ['id' => 'P\\Tests\\Feature\\PlanTest::__pest_evaluable_it_lists_plans_for_guests', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
            ['id' => 'P\\Tests\\Feature\\PlanTest::it charges by seat with data set "(3)"', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
            ['id' => 'Tests\\Unit\\MoneyTest::testRoundsHalfUp', 'file' => 'tests/Unit/MoneyTest.php', 'groups' => []],
            ['id' => 'Tests\\Feature\\InactiveTest::test_people_inactive_for_at_least_30_days_show', 'file' => 'tests/Feature/InactiveTest.php', 'groups' => []],
        ], []);

        $this->assertSame([
            'Owners can rename teams',
            'It lists plans for guests',
            // A data set is one more run of the same check.
            'It charges by seat',
            'Rounds half up',
            'People inactive for at least 30 days show',
        ], array_map($map->sentence(...), [0, 1, 2, 3, 4]));
    }
}
