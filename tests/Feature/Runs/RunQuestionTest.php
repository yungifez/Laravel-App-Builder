<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Context\ProjectNotes;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\CodingAgentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

class RunQuestionTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected FakeCodingAgent $coder;

    protected const QUESTION = [
        'text' => 'Can customers use more than one location?',
        'why' => 'It decides how bookings and bills are kept apart.',
        'options' => ['Yes', 'No'],
        'recommended' => 'No',
        'touches' => ['data_shape'],
        'reversible' => false,
        'easier_after_seeing' => false,
    ];

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

        // Once the questions are settled, the coding agent adds a file.
        $coder = $this->coder = new FakeCodingAgent('anthropic', function (Workspace $workspace) {
            File::put(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/app/Location.php", "<?php\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.');
        });
        app(CodingAgentManager::class)->extend('claude', fn () => $coder);
    }

    public function test_a_high_consequence_question_pauses_the_run_until_the_owner_answers()
    {
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION], $this->plan()]);

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('Can customers use more than one location?', $run->question['text']);
        $this->assertNull($run->plan);
        $this->assertSame([], $this->coder->tasks);

        $this->actingAs($featureRequest->project->owner)
            ->get(route('feature-requests.show', $featureRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.question.text', 'Can customers use more than one location?')
                ->where('run.question.recommended', 'No')
                // The page says it is asked because it is hard to change later.
                ->where('run.question.reversible', false)
                ->where('featureRequest.can_retry', false));

        $this->post(route('feature-requests.answers.store', $featureRequest), ['answer' => 'Yes'])
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->question);
        $this->assertSame([['question' => 'Can customers use more than one location?', 'answer' => 'Yes', 'decided_by' => 'owner']], $run->answers);

        // The second plan knows the answer and may not ask again.
        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '- Can customers use more than one location? Yes')
            && str_contains($prompt->prompt, 'Do not ask the owner anything more'));

        // The answer is a decision in the notes, so no later change asks it.
        $notes = app(ProjectNotes::class)->files($featureRequest->project)['project.md'];
        $this->assertStringContainsString("## Decisions\n\n- Can customers use more than one location? Yes", $notes);
    }

    public function test_you_decide_uses_the_recommendation_without_recording_a_decision()
    {
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION], $this->plan()]);

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest);

        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $featureRequest), ['more_questions' => true]);

        $run->refresh();
        $this->assertSame([['question' => self::QUESTION['text'], 'answer' => 'No', 'decided_by' => 'builder']], $run->answers);
        $this->assertSame(3, $run->question_limit);
        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'The owner left this to you; use: No')
            && ! str_contains($prompt->prompt, 'Do not ask the owner anything more'));
        $this->assertStringNotContainsString('Decisions', app(ProjectNotes::class)->files($featureRequest->project)['project.md']);
    }

    public function test_the_planner_cannot_ask_past_the_limit()
    {
        config(['builder.construction.questions.before_building' => 0]);
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION]]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->question);
    }

    public function test_a_question_the_owner_could_change_later_is_built_on_the_recommendation_and_shown_with_the_decisions()
    {
        FeaturePlanner::fake([[...$this->plan(), 'question' => [...self::QUESTION, 'reversible' => true]]]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->question);
        $this->assertContains('Can customers use more than one location? I went with: No.', $run->plan['assumptions']);
        $this->assertSame(['question' => 'Can customers use more than one location?', 'option' => 'No', 'touches' => ['data_shape']], $run->events()->where('type', 'question_decided')->sole()->data);
    }

    public function test_a_question_that_is_easier_to_judge_after_trying_the_change_is_not_asked_first()
    {
        FeaturePlanner::fake([[...$this->plan(), 'question' => [...self::QUESTION, 'easier_after_seeing' => true]]]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->question);
    }

    public function test_only_the_configured_consequences_are_worth_stopping_for()
    {
        config(['builder.construction.questions.ask_about' => ['money']]);
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION]]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->question);
    }

    public function test_a_question_without_a_recommendation_or_tags_is_always_asked()
    {
        FeaturePlanner::fake([
            [...$this->plan(), 'question' => [...self::QUESTION, 'reversible' => true, 'recommended' => 'Maybe']],
            [...$this->plan(), 'question' => array_diff_key(self::QUESTION, ['touches' => true])],
        ]);

        $first = app(StartRun::class)->handle($this->request())->refresh();
        $second = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $first->status);
        $this->assertNull($first->question['recommended']);
        $this->assertSame(RunStatus::NeedsUserDecision, $second->status);
    }

    public function test_only_an_offered_answer_is_accepted_and_only_while_the_run_waits()
    {
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION]]);

        $featureRequest = $this->request();
        app(StartRun::class)->handle($featureRequest);

        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $featureRequest), ['answer' => 'Maybe'])
            ->assertSessionHasErrors(['answer' => 'Pick one of the answers.']);

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.answers.store', $featureRequest), ['answer' => 'Yes'])
            ->assertForbidden();

        $other = FeatureRequest::factory()->for($featureRequest->project)->create();
        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $other), ['answer' => 'Yes'])
            ->assertSessionHasErrors(['answer' => 'There is no question waiting for an answer.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(): array
    {
        return [
            'summary' => 'Customers book at a location.',
            'acceptance_criteria' => ['A booking belongs to a location.'],
            'cases' => [['base' => 'A booking made at a location shows that location.', 'alternate' => null, 'no_alternate' => 'There is one way to make a booking.', 'exception' => 'A booking for a location that does not exist is refused.', 'no_exception' => null]],
            'assumptions' => [],
            'tasks' => ['Add a Location model.'],
            'steps' => [[
                'key' => 'location',
                'kind' => 'data',
                'label' => 'Locations',
                'file' => 'app/Location.php',
                'symbol' => 'Location',
                'detail' => 'Holds a location.',
            ]],
        ];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource(['.builder/project.md' => "# Project\n\nBookings for cleaners.\n"])]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let customers book at a location.']);
    }
}
