<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Enums\VerificationStatus;
use App\Features\StrictModels;
use App\Models\FeatureRequest;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class StrictModelsVerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected const RUN = ['sh', '-c', 'run the tests strictly', 'sh'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300]],
            'builder.verification.security.enabled' => false,
            'builder.verification.screens.enabled' => false,
            'builder.verification.change_evidence.enabled' => false,
            'builder.verification.access.enabled' => false,
            'builder.verification.time.enabled' => false,
            'builder.verification.strict' => ['enabled' => true, 'command' => self::RUN, 'timeout' => 60],
        ]);
    }

    /**
     * A change to the projects controller, and a test of it.
     */
    protected function change(): FeatureRequest
    {
        return FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Http/Controllers/ProjectController.php b/app/Http/Controllers/ProjectController.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/app/Http/Controllers/ProjectController.php',
            '@@ -0,0 +1 @@',
            '+<?php',
            'diff --git a/tests/Feature/ProjectTest.php b/tests/Feature/ProjectTest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/tests/Feature/ProjectTest.php',
            '@@ -0,0 +1 @@',
            '+<?php',
            '',
        ])]);
    }

    /**
     * Answer the strict run with these problems, as the extension writes
     * them.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string|null}>  $problems  Kind, model, attribute, place
     */
    protected function answer(array $problems): void
    {
        $this->driver->onExec = function (string $workspace, array $command) use ($problems) {
            $output = array_slice($command, 0, 4) === self::RUN
                ? implode('', array_map(fn (array $problem) => json_encode(['kind' => $problem[0], 'model' => $problem[1], 'attributes' => [$problem[2]], 'at' => $problem[3], 'test' => 'Tests\\Feature\\ProjectTest::test_it_saves'])."\n", $problems))
                : 'ok';

            return new CommandResult(exitCode: 0, output: $output, errorOutput: '', durationMs: 5);
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function strictResult(FeatureRequest $change): ?array
    {
        return collect($change->verifications()->sole()->results)->firstWhere('name', StrictModels::CHECK);
    }

    public function test_a_value_the_change_never_saves_sends_it_back(): void
    {
        $this->answer([[StrictModels::DISCARDED, 'App\\Models\\Project', 'colour', 'app/Http/Controllers/ProjectController.php:12']]);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = $this->strictResult($change);
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $this->assertSame(['checks', 'failed'], [$result['stage'], $result['outcome']]);
        $this->assertStringStartsWith('Project silently discarded [colour] at app/Http/Controllers/ProjectController.php:12', $result['output']);
        $this->assertSame(['colour'], $verification->evidence['strict'][0]['attributes']);

        // Only the change's own test runs.
        $runs = array_values(array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => array_slice($command, 0, 4) === self::RUN));
        $this->assertSame([[...self::RUN, 'tests/Feature/ProjectTest.php']], $runs);
    }

    public function test_loading_one_record_at_a_time_is_only_a_note(): void
    {
        $this->answer([[StrictModels::LAZY, 'App\\Models\\Project', 'tasks', 'app/Http/Controllers/ProjectController.php:9']]);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $result = $this->strictResult($change);
        $this->assertSame(VerificationStatus::Unverified, $change->verifications()->sole()->status, 'nothing failed; the change asked for nothing to accept');
        $this->assertSame('passed', $result['outcome']);
        $this->assertStringStartsWith('Note, not a failure', $result['output']);
    }

    public function test_a_problem_in_code_the_change_did_not_touch_is_not_its_own(): void
    {
        $this->answer([[StrictModels::MISSING, 'App\\Models\\User', 'nickname', 'app/Http/Controllers/ProfileController.php:12']]);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $this->assertSame(VerificationStatus::Unverified, $change->verifications()->sole()->status, 'nothing failed; the change asked for nothing to accept');
        $this->assertSame('passed', $this->strictResult($change)['outcome']);
        $this->assertArrayNotHasKey('strict', $change->verifications()->sole()->evidence ?? []);
    }
}
