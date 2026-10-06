<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\ShapePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\ShapeQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

/**
 * A new record's shape that is hard to change later is shown to the owner
 * before it is built, through the same pause as the planner's questions.
 */
class ShapeQuestionTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const ASKED = 'For each booking I keep: the room, when it starts, a note if there is one, how it went (pending or done) and who booked.';

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
        ]);

        $coder = new FakeCodingAgent('anthropic', function (Workspace $workspace) {
            File::put(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/app/Booking.php", "<?php\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.');
        });
        app(CodingAgentManager::class)->extend('claude', fn () => $coder);
    }

    public function test_a_shape_that_is_hard_to_change_later_waits_for_the_owner_and_is_built_as_they_answer()
    {
        $this->plans($this->fields());

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        // One line to answer, the few details that are hard to undo, and the
        // whole shape behind "The plan".
        $this->assertSame('Shall I set up bookings like this?', $run->question['text']);
        $this->assertSame([
            'Each booking must have the room, when it starts and how it went.',
            'How it went is one of pending or done.',
        ], $run->question['glance']);
        $this->assertSame([self::ASKED], $run->question['details']);
        $this->assertSame([ShapeQuestion::YES, ShapeQuestion::OPTIONAL, ShapeQuestion::TYPED], $run->question['options']);
        $this->assertSame(ShapeQuestion::YES, $run->question['recommended']);
        $this->assertFalse($run->question['reversible']);
        $this->assertNull($run->plan);

        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $featureRequest), ['answer' => ShapeQuestion::OPTIONAL])
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['room' => false, 'starts_at' => false, 'note' => false, 'outcome' => false, 'user' => true], $this->required($run));
        // The answer is applied to the plan it was about: one plan and one
        // shape, not planned again.
        $this->assertSame(2, $this->plannerCalls($run));
    }

    public function test_choices_can_be_typed_instead_and_you_decide_builds_the_shape_as_planned()
    {
        $this->plans($this->fields(), $this->fields());

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest);

        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $featureRequest), ['answer' => ShapeQuestion::TYPED]);

        $outcome = collect($run->refresh()->plan['data_shape'][0]['fields'])->firstWhere('name', 'outcome');
        $this->assertSame(['string', []], [$outcome['type'], $outcome['choices']]);

        $this->plans($this->fields(), $this->fields());

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest);

        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $featureRequest), []);

        $run->refresh();
        $this->assertSame([['question' => 'Shall I set up bookings like this?', 'asked' => self::ASKED, 'answer' => ShapeQuestion::YES, 'decided_by' => 'builder']], $run->answers);
        $this->assertSame(['room' => true, 'starts_at' => true, 'note' => false, 'outcome' => true, 'user' => true], $this->required($run));
    }

    public function test_a_shape_that_is_easy_to_change_later_builds_without_asking()
    {
        // Details that may be left out, a yes or no, and who added it.
        $this->plans([
            ['name' => 'note', 'type' => 'text', 'required' => false, 'choices' => [], 'of' => '', 'label' => 'a note'],
            ['name' => 'paid', 'type' => 'boolean', 'required' => true, 'choices' => [], 'of' => '', 'label' => 'whether it is paid'],
            ['name' => 'user', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'User', 'label' => 'who booked'],
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->question);
        $this->assertSame([], $run->answers ?? []);
    }

    public function test_a_shape_is_built_as_planned_when_no_more_questions_may_be_asked_or_shapes_are_not_worth_asking_about()
    {
        config(['builder.construction.questions.before_building' => 0]);
        $this->plans($this->fields());

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['room' => true, 'starts_at' => true, 'note' => false, 'outcome' => true, 'user' => true], $this->required($run));

        config([
            'builder.construction.questions.before_building' => 1,
            'builder.construction.questions.ask_about' => ['money', 'access'],
        ]);
        $this->plans($this->fields());

        $this->assertSame(RunStatus::Verifying, app(StartRun::class)->handle($this->request())->refresh()->status);
    }

    public function test_an_answer_to_the_planners_own_question_plans_again_and_the_shape_answer_after_it_does_not()
    {
        config(['builder.construction.questions.before_building' => 2]);
        $this->plans($this->fields());
        FeaturePlanner::fake([
            [...$this->plan(), 'question' => ['text' => 'Can members book for a guest?', 'why' => 'It decides who a booking is for.', 'options' => ['Yes', 'No'], 'recommended' => 'No', 'touches' => ['access'], 'reversible' => false, 'easier_after_seeing' => false]],
            $this->plan(),
        ]);

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest)->refresh();
        $this->assertSame('Can members book for a guest?', $run->question['text']);

        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $featureRequest), ['answer' => 'No']);
        $this->assertSame('Shall I set up bookings like this?', $run->refresh()->question['text']);

        $this->post(route('feature-requests.answers.store', $featureRequest), ['answer' => ShapeQuestion::OPTIONAL]);

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['room' => false, 'starts_at' => false, 'note' => false, 'outcome' => false, 'user' => true], $this->required($run));
        // Two plans, as the first answer changes the plan, and one shape.
        $this->assertSame(3, $this->plannerCalls($run));
    }

    protected function plannerCalls(Run $run): int
    {
        return $run->events()->where('type', 'model_call')->where('data->role', 'planner')->count();
    }

    /**
     * @return array<string, bool>
     */
    protected function required(Run $run): array
    {
        return collect($run->plan['data_shape'][0]['fields'])->mapWithKeys(fn (array $field) => [$field['name'] => $field['required']])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fields(): array
    {
        return [
            ['name' => 'room', 'type' => 'string', 'required' => true, 'choices' => [], 'of' => '', 'label' => 'the room'],
            ['name' => 'starts_at', 'type' => 'datetime', 'required' => true, 'choices' => [], 'of' => '', 'label' => 'when it starts'],
            ['name' => 'note', 'type' => 'text', 'required' => false, 'choices' => [], 'of' => '', 'label' => 'a note'],
            ['name' => 'outcome', 'type' => 'choice', 'required' => true, 'choices' => ['pending', 'done'], 'of' => '', 'label' => 'how it went'],
            ['name' => 'user', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'User', 'label' => 'who booked'],
        ];
    }

    /**
     * Fake one plan for each time the run plans, each with the given
     * fields for its new booking record.
     *
     * @param  list<array<string, mixed>>  ...$shapes
     */
    protected function plans(array ...$shapes): void
    {
        FeaturePlanner::fake(array_map(fn () => $this->plan(), $shapes));
        ShapePlanner::fake(array_map(fn (array $fields) => ['data_shape' => [[
            'name' => 'Booking',
            'label' => 'booking',
            'fields' => $fields,
            'access' => null,
        ]]], $shapes));
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
            'new_records' => true,
        ];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource(['.builder/project.md' => "# Project\n\nRooms for members.\n"])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let members book rooms.']);
    }
}
