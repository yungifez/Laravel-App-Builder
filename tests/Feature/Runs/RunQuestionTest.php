<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeatureCoder;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\RunStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class RunQuestionTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const QUESTION = [
        'text' => 'Can customers use more than one location?',
        'why' => 'It decides how bookings and bills are kept apart.',
        'options' => ['Yes', 'No'],
        'recommended' => 'No',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'agent',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.models.coder' => ['provider' => 'anthropic', 'model' => 'coder-model'],
            'builder.models.reviewer' => ['provider' => 'openai', 'model' => 'reviewer-model'],
        ]);
    }

    public function test_a_high_consequence_question_pauses_the_run_until_the_owner_answers()
    {
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION], $this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'app/Location.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 0]),
            'Done.',
        ]);

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('Can customers use more than one location?', $run->question['text']);
        $this->assertNull($run->plan);
        FeatureCoder::assertNeverPrompted();

        $this->actingAs($featureRequest->project->owner)
            ->get(route('feature-requests.show', $featureRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.question.text', 'Can customers use more than one location?')
                ->where('run.question.recommended', 'No')
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
        $notes = app(ProjectRepository::class)->show($featureRequest->project, app(ProjectRepository::class)->head($featureRequest->project), '.builder/project.md');
        $this->assertStringContainsString("## Decisions\n\n- Can customers use more than one location? Yes", (string) $notes);
    }

    public function test_you_decide_uses_the_recommendation_without_recording_a_decision()
    {
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION], $this->plan()]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'app/Location.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 0]),
            'Done.',
        ]);

        $featureRequest = $this->request();
        $run = app(StartRun::class)->handle($featureRequest);

        $this->actingAs($featureRequest->project->owner)
            ->post(route('feature-requests.answers.store', $featureRequest), ['more_questions' => true]);

        $run->refresh();
        $this->assertSame([['question' => self::QUESTION['text'], 'answer' => 'No', 'decided_by' => 'builder']], $run->answers);
        $this->assertSame(3, $run->question_limit);
        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'The owner left this to you; use: No')
            && ! str_contains($prompt->prompt, 'Do not ask the owner anything more'));
        $repository = app(ProjectRepository::class);
        $repository->import($featureRequest->project);
        $this->assertStringNotContainsString('Decisions', (string) $repository->show($featureRequest->project, 'HEAD', '.builder/project.md'));
    }

    public function test_the_planner_cannot_ask_past_the_limit()
    {
        config(['builder.construction.questions.before_building' => 0]);
        FeaturePlanner::fake([[...$this->plan(), 'question' => self::QUESTION]]);
        FeatureCoder::fake([
            new ToolCall('call-1', 'write_file', ['path' => 'app/Location.php', 'contents' => "<?php\n", 'expected_sha256' => null, 'expected_revision' => 0]),
            'Done.',
        ]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->question);
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
