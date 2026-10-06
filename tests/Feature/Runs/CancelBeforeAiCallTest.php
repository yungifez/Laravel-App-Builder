<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\ShapePlanner;
use App\Ai\Agents\TestWriter;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\CodingAgentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

/**
 * A change the owner cancels while it is being planned makes no paid AI
 * call after that: it does not hold the worker or spend credit on work
 * nobody will see.
 */
class CancelBeforeAiCallTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected int $written = 0;

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
            'builder.construction.questions.ask_about' => [],
            'builder.verification.written_first.enabled' => true,
            'builder.verification.written_first.attempts' => 2,
        ]);

        $coder = new FakeCodingAgent('anthropic', function (Workspace $workspace) {
            File::put(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/app/Booking.php", "<?php\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.');
        });
        app(CodingAgentManager::class)->extend('claude', fn () => $coder);

        ShapePlanner::fake([$this->shape()]);
    }

    public function test_a_change_cancelled_while_it_is_planned_asks_for_no_shape_and_no_tests(): void
    {
        FeaturePlanner::fake(function () {
            $this->cancel();

            return $this->plan();
        });
        TestWriter::fake([$this->tests()]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Cancelled, $run->status);
        ShapePlanner::assertNeverPrompted();
        TestWriter::assertNeverPrompted();
    }

    public function test_a_change_cancelled_while_its_tests_are_written_is_not_asked_for_them_again(): void
    {
        FeaturePlanner::fake([$this->plan()]);
        TestWriter::fake(function () {
            $this->written++;
            $this->cancel();

            // Refused output: without the cancel, they are asked for again.
            return ['files' => [], 'tests' => []];
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertSame(1, $this->written);
        $this->assertFalse($run->events()->where('type', 'tests_not_written')->exists());
    }

    public function test_a_change_nobody_cancels_makes_every_call_as_before(): void
    {
        FeaturePlanner::fake([$this->plan()]);
        TestWriter::fake(function () {
            $this->written++;

            return $this->written === 1 ? ['files' => [], 'tests' => []] : $this->tests();
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertNotSame(RunStatus::Cancelled, $run->status);
        $this->assertSame(2, $this->written);
        ShapePlanner::assertPrompted(fn () => true);
        $this->assertSame([['item' => 1, 'file' => 'tests/Feature/BookingTest.php', 'name' => 'a booked room shows on the list']], $run->plan['written_tests']);
    }

    /**
     * Ask to cancel the change being planned, as the owner's cancel does.
     */
    protected function cancel(): void
    {
        Run::query()->where('status', RunStatus::Planning)->update(['status' => RunStatus::Cancelling]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(): array
    {
        return [
            'summary' => 'Members book rooms.',
            'acceptance_criteria' => ['A member can book a room.'],
            'cases' => [['base' => 'A booked room shows on the list.', 'alternate' => null, 'no_alternate' => 'There is one way to book.', 'exception' => null, 'no_exception' => 'Nothing about a booking is refused.']],
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
            'new_records' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function shape(): array
    {
        return ['data_shape' => [[
            'name' => 'Booking',
            'label' => 'booking',
            'fields' => [['name' => 'room', 'type' => 'string', 'required' => true, 'choices' => [], 'of' => '', 'label' => 'the room']],
            'access' => null,
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function tests(): array
    {
        return [
            'files' => [['path' => 'tests/Feature/BookingTest.php', 'contents' => "<?php\n\ntest('a booked room shows on the list', fn () => expect(true)->toBeTrue());\n"]],
            'tests' => [['item' => 1, 'file' => 'tests/Feature/BookingTest.php', 'name' => 'a booked room shows on the list']],
        ];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource(['.builder/project.md' => "# Project\n\nRooms for members.\n"])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let members book rooms.']);
    }
}
