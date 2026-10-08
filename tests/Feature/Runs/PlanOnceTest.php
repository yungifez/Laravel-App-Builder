<?php

namespace Tests\Feature\Runs;

use App\Actions\Context\CompileContext;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\ShapePlanner;
use App\Ai\Agents\TestWriter;
use App\Context\ContextPack;
use App\Context\ProjectContext;
use App\Enums\AgentOutcomeStatus;
use App\Enums\ContextMode;
use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
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
use RuntimeException;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

/**
 * A run that stops on our side after its plan and tests are done does not
 * pay for them again when it is tried again. A new answer from the owner
 * still plans again, and a plan that never finished is not half reused.
 */
class PlanOnceTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected int $planned = 0;

    protected int $written = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class, ExecuteRun::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.agents.order' => ['claude'],
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.models.reviewer' => ['provider' => 'openai', 'model' => 'reviewer-model'],
            'builder.construction.questions.ask_about' => [],
            'builder.verification.written_first.enabled' => true,
            // Before the coder, as every change after a project's first.
            'builder.verification.written_first.beside' => false,
        ]);

        $coder = new FakeCodingAgent('anthropic', function (Workspace $workspace) {
            File::put(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/app/Booking.php", "<?php\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.');
        });
        app(CodingAgentManager::class)->extend('claude', fn () => $coder);

        FeaturePlanner::fake(function () {
            $this->planned++;

            return $this->plan();
        });
        ShapePlanner::fake(fn () => $this->shape());
    }

    public function test_a_run_stopped_after_its_plan_and_tests_does_not_pay_for_them_again(): void
    {
        TestWriter::fake(function () {
            $this->written++;

            return $this->tests();
        });
        $this->stopOnceWhileCompilingContext();
        $run = $this->started();

        $this->tryRun($run);
        $this->assertSame(RunStatus::Planning, $run->refresh()->status);

        $this->tryRun($run);

        $this->assertSame([1, 1], [$this->planned, $this->written]);
        $this->assertSame(1, $run->events()->where('type', 'plan_reused')->count());
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
        $this->assertSame([['item' => 1, 'file' => 'tests/Feature/BookingTest.php', 'name' => 'a booked room shows on the list']], $run->plan['written_tests']);
    }

    public function test_a_new_answer_from_the_owner_plans_again(): void
    {
        TestWriter::fake(fn () => $this->tests());
        $this->stopOnceWhileCompilingContext();
        $run = $this->started();

        $this->tryRun($run);
        $run->update(['answers' => [['question' => 'Who may book?', 'answer' => 'Members only', 'decided_by' => 'owner']]]);
        $this->tryRun($run);

        $this->assertSame(2, $this->planned);
        $this->assertFalse($run->events()->where('type', 'plan_reused')->exists());
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
    }

    public function test_a_plan_that_never_finished_is_made_again_whole(): void
    {
        TestWriter::fake(function () {
            // The worker stops while the first tests are written.
            if ($this->written++ === 0) {
                throw new RuntimeException('The worker stopped.');
            }

            return $this->tests();
        });
        $run = $this->started();

        $this->tryRun($run);
        $this->assertFalse($run->events()->where('type', 'planned')->exists());

        $this->tryRun($run);

        $this->assertSame([2, 2], [$this->planned, $this->written]);
        $this->assertFalse($run->events()->where('type', 'plan_reused')->exists());
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
    }

    /**
     * Run the job once, as a queue worker does, and let a stop on our side
     * end only this try.
     */
    protected function tryRun(Run $run): void
    {
        try {
            app()->call([new ExecuteRun($run->refresh()), 'handle']);
        } catch (RuntimeException $exception) {
            $this->assertSame('The worker stopped.', $exception->getMessage());
        }
    }

    /**
     * Stop the first try after the plan and the tests are made, as a worker
     * restart would.
     */
    protected function stopOnceWhileCompilingContext(): void
    {
        $this->app->instance(CompileContext::class, new class extends CompileContext
        {
            protected bool $stopped = false;

            public function handle(ProjectContext $context, array $targets, ?ContextMode $mode = null, array $files = []): ContextPack
            {
                if (! $this->stopped) {
                    $this->stopped = true;

                    throw new RuntimeException('The worker stopped.');
                }

                return parent::handle($context, $targets, $mode, $files);
            }
        });
    }

    protected function started(): Run
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource(['.builder/project.md' => "# Project\n\nRooms for members.\n"])]);

        return app(StartRun::class)->handle(FeatureRequest::factory()->for($project)->create(['prompt' => 'Let members book rooms.']))->refresh();
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
}
