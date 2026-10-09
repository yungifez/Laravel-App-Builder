<?php

namespace Tests\Feature\Operations;

use App\Actions\Operations\FindAttentionItems;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\GenericReviewer;
use App\Ai\Agents\NotesDrafter;
use App\Ai\Agents\NotesKeeper;
use App\Ai\Agents\ShapePlanner;
use App\Ai\Agents\TestWriter;
use App\Console\Commands\CheckAnswerFormats;
use App\Models\AnswerFormatCheck;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\GreetingWriter;
use Tests\Fixtures\Agents\UntieredWriter;
use Tests\TestCase;

class AnswerFormatCheckTest extends TestCase
{
    use RefreshDatabase;

    protected const AGENTS = [ChangeReviewer::class, FeaturePlanner::class, GenericReviewer::class, NotesDrafter::class, NotesKeeper::class, ShapePlanner::class, TestWriter::class];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.anthropic.key' => 'anthropic-test-key',
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.models.reviewer' => ['provider' => 'anthropic', 'model' => 'reviewer-model'],
            'builder.models.failover' => [],
        ]);

        foreach (self::AGENTS as $agent) {
            $agent::fake();
        }
    }

    /**
     * @return array<string, array{accepted: bool, role: string|null, reason: string|null}>
     */
    private function results(): array
    {
        return AnswerFormatCheck::query()->get()
            ->mapWithKeys(fn (AnswerFormatCheck $check) => [class_basename($check->agent) => ['accepted' => $check->accepted, 'role' => $check->role, 'reason' => $check->reason]])
            ->sortKeys()->all();
    }

    private function attention(): ?array
    {
        return collect(app(FindAttentionItems::class)->handle(7)['items'])->firstWhere('key', 'ai_format_refused');
    }

    public function test_every_agent_is_tried_on_its_own_tier_with_a_tiny_prompt_and_none_needs_attention()
    {
        $this->artisan('ai:check-formats')->assertSuccessful();

        $this->assertSame([
            'ChangeReviewer' => ['accepted' => true, 'role' => 'reviewer', 'reason' => null],
            'FeaturePlanner' => ['accepted' => true, 'role' => 'planner', 'reason' => null],
            'GenericReviewer' => ['accepted' => true, 'role' => 'reviewer', 'reason' => null],
            'NotesDrafter' => ['accepted' => true, 'role' => 'planner', 'reason' => null],
            'NotesKeeper' => ['accepted' => true, 'role' => 'reviewer', 'reason' => null],
            'ShapePlanner' => ['accepted' => true, 'role' => 'planner', 'reason' => null],
            'TestWriter' => ['accepted' => true, 'role' => 'reviewer', 'reason' => null],
        ], $this->results());
        // No project data goes with it.
        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === CheckAnswerFormats::PROMPT && $prompt->model === 'planner-model');
        NotesKeeper::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === CheckAnswerFormats::PROMPT && $prompt->model === 'reviewer-model');
        $this->assertNull($this->attention());
    }

    public function test_a_new_agent_is_found_without_being_listed_and_one_with_no_tier_is_named()
    {
        config(['builder.answer_formats.directories' => [app_path('Ai/Agents'), base_path('tests/Fixtures/Agents')]]);
        GreetingWriter::fake();
        UntieredWriter::fake();

        $this->artisan('ai:check-formats')->assertFailed();

        $results = $this->results();
        $this->assertSame(['accepted' => true, 'role' => 'reviewer', 'reason' => null], $results['GreetingWriter']);
        $this->assertSame(['accepted' => false, 'role' => null, 'reason' => 'no_tier'], $results['UntieredWriter']);
        GreetingWriter::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === CheckAnswerFormats::PROMPT);
        UntieredWriter::assertNeverPrompted();
        $this->assertCount(9, $results);
    }

    public function test_a_refused_format_is_reported_with_the_service_error_the_others_still_run_and_it_needs_attention()
    {
        ChangeReviewer::fake(function () {
            throw new RequestException(new Response(new Psr7Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'The compiled grammar is too large.']]))));
        });

        $this->artisan('ai:check-formats')
            ->expectsOutputToContain('request_refused')
            ->assertFailed();

        $check = AnswerFormatCheck::query()->where('agent', ChangeReviewer::class)->sole();
        $this->assertFalse($check->accepted);
        $this->assertSame('request_refused', $check->reason);
        $this->assertSame(['status' => 400, 'type' => 'invalid_request_error', 'message' => 'The compiled grammar is too large.'], $check->service_error);
        $this->assertSame(count(self::AGENTS) - 1, AnswerFormatCheck::query()->where('accepted', true)->count());
        TestWriter::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === CheckAnswerFormats::PROMPT);

        $item = $this->attention();
        $this->assertSame(1, $item['count']);
        $this->assertSame('ChangeReviewer (reviewer)', $item['records'][0]['label']);
        $this->assertStringStartsWith('request_refused invalid_request_error', $item['records'][0]['detail']);

        // Once the format is accepted again, it no longer needs attention.
        ChangeReviewer::fake();
        $this->artisan('ai:check-formats')->assertSuccessful();
        $this->assertNull($this->attention());
    }
}
