<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\WriteBrief;
use App\Actions\Runs\WriteTestsFirst;
use App\Ai\Agents\TestWriter;
use App\Models\Run;
use App\Runs\Plan;
use App\Runs\ReshapedSchema;
use App\Runs\WrongWrittenTests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * A test written before the change can guess a name the app does not
 * have. The coder says so instead of bending working schema to fit it, the
 * test is checked against the app's real tables and corrected, and a
 * change that renames working schema anyway is caught.
 */
class WrongWrittenTestTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const FILE = 'tests/Feature/Teams/LeaveTeamTest.php';

    protected const NAME = 'a member can leave: the team keeps its owner';

    public function test_the_coder_names_a_wrong_written_test_with_its_reason(): void
    {
        $reply = "Built leaving a team.\n\nTEST WRONG ".self::FILE.' :: '.self::NAME.": it expects a team_members table, but members are in team_user.\n";

        $this->assertSame([[
            'item' => 1,
            'file' => self::FILE,
            'name' => self::NAME,
            'message' => 'it expects a team_members table, but members are in team_user.',
            'by' => 'coder',
        ]], app(WrongWrittenTests::class)->reported($reply, $this->plan()));
    }

    public function test_a_test_named_twice_counts_once_and_the_brief_tells_the_coder_how_to_say_so(): void
    {
        $line = '- TEST WRONG '.self::FILE.' :: '.self::NAME.': no is_personal column.';

        $this->assertCount(1, app(WrongWrittenTests::class)->reported("{$line}\n{$line}", $this->plan()));

        $run = Run::factory()->implementing()->create(['driver' => 'sdk']);
        $this->assertStringContainsString(WriteBrief::TEST_WRONG, app(WriteBrief::class)->handle($run, $this->plan()));
        $this->assertStringNotContainsString('TEST WRONG', app(WriteBrief::class)->handle($run, new Plan(summary: 'Leave a team.', acceptanceCriteria: ['A member can leave.'])));
    }

    public function test_a_line_that_names_no_written_test_exactly_is_ignored(): void
    {
        $reported = app(WrongWrittenTests::class)->reported(implode("\n", [
            'TEST WRONG '.self::FILE.' :: a member can leave: wrong.',
            'TEST WRONG tests/Feature/OtherTest.php :: '.self::NAME.': wrong.',
            'TEST WRONG '.self::FILE.' :: '.self::NAME,
        ]), $this->plan());

        $this->assertSame([], $reported);
        $this->assertSame([], app(WrongWrittenTests::class)->reported('TEST WRONG '.self::FILE.' :: '.self::NAME.': wrong.', new Plan(summary: 'Leave a team.')));
    }

    public function test_the_coders_wrong_test_is_rewritten_against_the_apps_real_tables(): void
    {
        [$run] = $this->implementingRun($this->makeProjectSource([
            'database/migrations/2026_01_01_000000_create_teams_table.php' => "<?php\nSchema::create('teams', function (Blueprint \$table) {\n    \$table->id();\n    \$table->string('name');\n});\n",
            'database/migrations/2026_01_02_000000_create_team_user_table.php' => "<?php\nSchema::create('team_user', function (Blueprint \$table) {\n    \$table->foreignId('team_id');\n    \$table->foreignId('user_id');\n    \$table->string('role');\n    \$table->index('role');\n});\n",
            'database/migrations/2026_01_03_000000_change_teams_table.php' => "<?php\nSchema::table('teams', function (Blueprint \$table) {\n    \$table->string('slug');\n    \$table->dropColumn('name');\n});\n",
        ]));
        $corrected = "<?php\n\ntest('".self::NAME."', fn () => expect(true)->toBeTrue());\n";
        TestWriter::fake([[
            'files' => [['path' => self::FILE, 'contents' => $corrected]],
            'tests' => [['item' => 1, 'file' => self::FILE, 'name' => self::NAME]],
        ]]);

        ['plan' => $plan] = app(WriteTestsFirst::class)->rewrite($run, $this->plan(), $run->workspace, [[
            'item' => 1, 'file' => self::FILE, 'name' => self::NAME, 'message' => 'it expects a team_members table, but members are in team_user.', 'by' => 'coder',
        ]]);

        $this->assertSame($corrected, $plan?->writtenFiles[self::FILE]);
        TestWriter::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'The coder who built the change says each test above is wrong')
            && str_contains($prompt->prompt, '1. "'.self::NAME.'" checks this item: ')
            && str_contains($prompt->prompt, "The coder said:\n\n```\nit expects a team_members table")
            && str_contains($prompt->prompt, "## Tables in the app now, with their columns\n\n- teams: slug\n- team_user: team_id, user_id, role"));
    }

    public function test_a_reported_test_the_file_does_not_hold_is_left_out_and_the_others_are_still_corrected(): void
    {
        [$run] = $this->implementingRun($this->makeProjectSource());
        $corrected = "<?php\n\ntest('".self::NAME."', fn () => expect(true)->toBeTrue());\n";
        TestWriter::fake([[
            'files' => [['path' => self::FILE, 'contents' => $corrected]],
            'tests' => [['item' => 1, 'file' => self::FILE, 'name' => self::NAME]],
        ]]);
        $plan = $this->plan();
        $plan = $plan->withWrittenTests($plan->writtenFiles, [...$plan->writtenTests, ['item' => 1, 'file' => self::FILE, 'name' => 'a member who leaves loses access']]);

        ['plan' => $rewritten, 'tests' => $asked] = app(WriteTestsFirst::class)->rewrite($run, $plan, $run->workspace, [
            ['item' => 1, 'file' => self::FILE, 'name' => 'a member who leaves loses access', 'message' => 'no such page.', 'by' => 'coder'],
            ['item' => 1, 'file' => self::FILE, 'name' => self::NAME, 'message' => 'members are in team_user.', 'by' => 'coder'],
        ]);

        $this->assertSame([self::NAME], array_column($asked, 'name'));
        $this->assertSame($corrected, $rewritten?->writtenFiles[self::FILE]);
        TestWriter::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '1. "'.self::NAME.'"') && ! str_contains($prompt->prompt, 'loses access'));
    }

    public function test_a_change_that_renames_or_drops_working_schema_or_edits_a_migration_that_ran_is_found(): void
    {
        $patch = $this->migration('2026_10_08_000000_rename_team_user.php', "Schema::rename('team_user', 'team_members');\nSchema::table('teams', function (Blueprint \$table) {\n    \$table->renameColumn('personal', 'is_personal');\n    \$table->dropColumn(['slug']);\n});")
            ."diff --git a/database/migrations/2026_01_01_000000_create_teams_table.php b/database/migrations/2026_01_01_000000_create_teams_table.php\nindex 1111111..2222222 100644\n--- a/database/migrations/2026_01_01_000000_create_teams_table.php\n+++ b/database/migrations/2026_01_01_000000_create_teams_table.php\n@@ -1 +1 @@\n-\$table->string('name');\n+\$table->string('title');\n";

        $this->assertSame([
            'It renames the personal column.',
            'It removes the slug column.',
            'It renames the team_user table.',
            'It changes database/migrations/2026_01_01_000000_create_teams_table.php, a migration that already ran.',
        ], app(ReshapedSchema::class)->in($patch, $this->plan()));
    }

    public function test_a_change_the_plan_asks_for_or_a_table_the_change_makes_itself_is_not_found(): void
    {
        $patch = $this->migration('2026_10_08_000000_create_leaves_table.php', "public function up(): void\n{\n    Schema::create('leaves', fn (Blueprint \$table) => \$table->id());\n    Schema::table('teams', fn (Blueprint \$table) => \$table->dropColumn('bio'));\n}\n\npublic function down(): void\n{\n    Schema::dropIfExists('leaves');\n    Schema::dropIfExists('teams');\n}");

        $asked = new Plan(summary: 'Remove the bio from teams, and let members leave.', tasks: ['Drop the bio column.']);

        $this->assertSame([], app(ReshapedSchema::class)->in($patch, $asked));
        $this->assertSame(['It removes the bio column.'], app(ReshapedSchema::class)->in($patch, $this->plan()));
    }

    public function test_a_change_with_no_migration_reshapes_nothing(): void
    {
        $patch = "diff --git a/app/Models/Team.php b/app/Models/Team.php\nindex 1111111..2222222 100644\n--- a/app/Models/Team.php\n+++ b/app/Models/Team.php\n@@ -1 +1 @@\n-// renameColumn('name')\n+// dropColumn('name')\n";

        $this->assertSame([], app(ReshapedSchema::class)->in($patch, $this->plan()));
        $this->assertSame([], app(ReshapedSchema::class)->in(null, $this->plan()));
    }

    protected function plan(): Plan
    {
        return new Plan(
            summary: 'Let a member leave a team.',
            acceptanceCriteria: ['A member can leave a team.'],
            tasks: ['Add a leave action.'],
            cases: [['criterion' => 1, 'kind' => 'base', 'says' => 'A member leaves a team of three.', 'none' => null]],
            writtenTests: [['item' => 1, 'file' => self::FILE, 'name' => self::NAME]],
            writtenFiles: [self::FILE => "<?php\n\ntest('".self::NAME."', fn () => expect(DB::table('team_members')->count())->toBe(0));\n"],
        );
    }

    protected function migration(string $name, string $body): string
    {
        $lines = explode("\n", "<?php\n\n{$body}\n");

        return "diff --git a/database/migrations/{$name} b/database/migrations/{$name}\nnew file mode 100644\nindex 0000000..1111111\n--- /dev/null\n+++ b/database/migrations/{$name}\n@@ -0,0 +1,".count($lines)." @@\n".implode("\n", array_map(fn (string $line) => "+{$line}", $lines))."\n";
    }
}
