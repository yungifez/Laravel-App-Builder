<?php

namespace Tests\Feature\Runs;

use App\Actions\Context\AssessVerifyItems;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\TestWriter;
use App\Enums\AgentOutcomeStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\Plan;
use App\Runs\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

/**
 * Tests written before the change keep every file that keeps the rules. Only
 * the refused files are asked for again, and an item still without a test
 * is left to the coder and is never counted as tested.
 */
class PartlyWrittenTestsTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const BASE = 'tests/Feature/BookingTest.php';

    protected const REFUSAL = 'tests/Feature/BookingRefusalTest.php';

    protected FakeCodingAgent $coder;

    /** @var list<string> */
    protected array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.agents.order' => ['claude'],
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.models.reviewer' => ['provider' => 'openai', 'model' => 'reviewer-model'],
            'builder.verification.written_first.enabled' => true,
            // Before the coder, as every change after a project's first.
            'builder.verification.written_first.beside' => false,
            'builder.verification.written_first.attempts' => 2,
        ]);

        $coder = $this->coder = new FakeCodingAgent('anthropic', function (Workspace $workspace) {
            File::put(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/app/Booking.php", "<?php\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.');
        });
        app(CodingAgentManager::class)->extend('claude', fn () => $coder);

        FeaturePlanner::fake([$this->plan()]);
    }

    public function test_one_refused_file_is_asked_for_again_alone_and_the_kept_one_stays(): void
    {
        $this->answers(
            $this->answer([self::BASE => $this->base(), self::REFUSAL => $this->refusal(assertsRefusal: false)]),
            $this->answer([self::REFUSAL => $this->refusal(assertsRefusal: true)]),
        );

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertCount(2, $this->asked);
        $this->assertStringContainsString('## Some of your previous tests were refused', $this->asked[1]);
        $this->assertStringContainsString('- '.self::BASE.': a booked room shows on the list (item 1)', $this->asked[1]);
        $this->assertStringContainsString('- '.self::REFUSAL.': The test "a booking with no room is refused" for item 2 is an exception case, but it asserts no refusal.', $this->asked[1]);
        $this->assertStringNotContainsString('Return every file and test again', $this->asked[1]);
        $this->assertSame([
            ['item' => 1, 'file' => self::BASE, 'name' => 'a booked room shows on the list'],
            ['item' => 2, 'file' => self::REFUSAL, 'name' => 'a booking with no room is refused'],
        ], $run->plan['written_tests']);
        $this->assertSame($this->base(), $run->plan['written_files'][self::BASE]);
        $this->assertArrayNotHasKey('dropped', $run->events()->where('type', 'tests_written')->sole()->data);
        $this->assertStringNotContainsString('## Items with no test written yet', $this->brief());
    }

    public function test_a_retry_that_is_refused_again_keeps_the_good_file_and_names_the_dropped_case(): void
    {
        $this->answers(
            $this->answer([self::BASE => $this->base(), self::REFUSAL => $this->refusal(assertsRefusal: false)]),
            $this->answer([self::REFUSAL => $this->refusal(assertsRefusal: false)]),
        );

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertCount(2, $this->asked);
        $this->assertSame([['item' => 1, 'file' => self::BASE, 'name' => 'a booked room shows on the list']], $run->plan['written_tests']);
        $this->assertSame([self::BASE], array_keys($run->plan['written_files']));
        $written = $run->events()->where('type', 'tests_written')->sole()->data;
        $this->assertSame([['item' => 2, 'case' => 'A member can book a room. (exception case: A booking with no room is refused.)']], $written['dropped']);
        $this->assertStringContainsString('asserts no refusal', implode("\n", $written['reasons']));
        $this->assertFalse($run->events()->where('type', 'tests_not_written')->exists());
        $this->assertStringContainsString("## Items with no test written yet\n\nNo test was written for these items. Write one test for each yourself", $this->brief());
        $this->assertStringContainsString('- 2. A member can book a room. (exception case: A booking with no room is refused.)', $this->brief());

        // The dropped item is untested until a test of the coder's is in the change.
        $items = app(AssessVerifyItems::class)->handle(Plan::fromArray($run->plan), new Review(true, 'Fine.'), (string) $run->featureRequest->patch, []);
        $this->assertSame('no_test', $items[1]['evidence']);
        $this->assertNull($items[1]['test_file']);
        $this->assertSame(self::BASE, $items[0]['test_file']);

        // The reviewer's word alone never makes it tested: its test must run and pass.
        $claimed = new Review(true, 'Fine.', verify: [
            ['criterion' => 1, 'test_file' => 'app/Booking.php', 'test_name' => 'a booked room shows on the list'],
            ['criterion' => 2, 'test_file' => self::BASE, 'test_name' => 'a booking with no room is refused'],
        ]);
        $plan = Plan::fromArray($run->plan);
        $patch = (string) $run->featureRequest->patch;
        $unreported = app(AssessVerifyItems::class)->handle($plan, $claimed, $patch, []);
        $this->assertSame(self::BASE, $unreported[0]['test_file'], 'A written test is not replaced by the reviewer\'s.');
        $this->assertSame('not_run', $unreported[1]['evidence']);
        $this->assertSame('claimed', app(AssessVerifyItems::class)->handle($plan, $claimed, $patch, [['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed']])[1]['evidence']);
    }

    public function test_when_every_file_is_refused_twice_the_coder_writes_the_tests_as_before(): void
    {
        $this->answers(
            $this->answer([self::REFUSAL => $this->refusal(assertsRefusal: false)]),
            $this->answer([self::REFUSAL => $this->refusal(assertsRefusal: false)]),
        );

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertCount(2, $this->asked);
        $this->assertStringContainsString("## Your previous tests were refused\n\n", $this->asked[1]);
        $this->assertStringContainsString('Return every file and test again, with this fixed.', $this->asked[1]);
        $this->assertSame([], $run->plan['written_tests']);
        $this->assertStringContainsString('asserts no refusal', $run->events()->where('type', 'tests_not_written')->sole()->data['error']);
        $this->assertFalse($run->events()->where('type', 'tests_written')->exists());
        $this->assertStringNotContainsString('## Tests already written', $this->brief());
    }

    /**
     * Fake the test writer's answers in turn, keeping each prompt.
     *
     * @param  array<string, mixed>  ...$outputs
     */
    protected function answers(array ...$outputs): void
    {
        TestWriter::fake(function (string $prompt) use ($outputs) {
            $this->asked[] = $prompt;

            return $outputs[count($this->asked) - 1];
        });
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, mixed>
     */
    protected function answer(array $files): array
    {
        $names = [self::BASE => [1, 'a booked room shows on the list'], self::REFUSAL => [2, 'a booking with no room is refused']];

        return [
            'files' => array_map(fn (string $path) => ['path' => $path, 'contents' => $files[$path]], array_keys($files)),
            'tests' => array_map(fn (string $path) => ['item' => $names[$path][0], 'file' => $path, 'name' => $names[$path][1]], array_keys($files)),
        ];
    }

    protected function base(): string
    {
        return "<?php\n\ntest('a booked room shows on the list', fn () => expect(true)->toBeTrue());\n";
    }

    protected function refusal(bool $assertsRefusal): string
    {
        return $assertsRefusal
            ? "<?php\n\ntest('a booking with no room is refused', fn () => \$this->post('/bookings')->assertForbidden());\n"
            : "<?php\n\ntest('a booking with no room is refused', fn () => expect(true)->toBeTrue());\n";
    }

    protected function brief(): string
    {
        return implode("\n\n", array_map(fn (AgentTask $task) => $task->prompt."\n\n".$task->instructions, $this->coder->tasks));
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(): array
    {
        return [
            'summary' => 'Members book rooms.',
            'acceptance_criteria' => ['A member can book a room.'],
            'cases' => [['base' => 'A booked room shows on the list.', 'alternate' => null, 'no_alternate' => 'There is one way to book.', 'exception' => 'A booking with no room is refused.', 'no_exception' => null]],
            'assumptions' => [],
            'tasks' => ['Add a Booking model.'],
            'steps' => [[
                'key' => 'booking',
                'kind' => 'data',
                'label' => 'Bookings',
                'file' => 'app/Booking.php',
                'symbol' => 'Booking',
                'detail' => 'Holds a booking.',
            ]],
        ];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource(['.builder/project.md' => "# Project\n\nRooms for members.\n"])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let members book rooms.']);
    }
}
