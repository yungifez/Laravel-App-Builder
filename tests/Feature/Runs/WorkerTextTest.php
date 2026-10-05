<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\GrantWorkerAccess;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Runs\Assumption;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The brief lint for the worker's side (architecture §11, §19). Whoever runs
 * the worker can read every name, description and answer the task server
 * gives, so none of it may name our machinery, a model or an internal field.
 */
class WorkerTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_a_worker_can_read_names_our_machinery()
    {
        config([
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model-7'],
            'builder.models.reviewer' => ['provider' => 'openai', 'model' => 'reviewer-model-7'],
            'builder.agents.workers.status_wait_seconds' => 0,
        ]);
        $run = Run::factory()->implementing()->create([
            'plan' => (new Plan(
                summary: 'Members can book a class.',
                acceptanceCriteria: ['A member can book a class with room left.'],
                tasks: ['Add booking.'],
                preserve: [['area' => 'classes', 'statement' => 'A full class takes no more bookings.']],
                assumptions: [new Assumption('A booking needs a signed-in member.')],
            ))->toArray(),
        ]);
        $token = app(GrantWorkerAccess::class)->handle($run);

        $texts = [
            // The server's own words; the protocol's keys are not ours.
            (string) json_encode(Arr::only($this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'worker', 'version' => '1']])['result'], ['serverInfo', 'instructions'])),
            (string) json_encode($this->rpc($token, 'tools/list')['result']['tools']),
            $this->tool($token, 'get_task'),
            $this->tool($token, 'check_status'),
        ];

        // A change sent back with problems is briefed again.
        $run->update(['feedback' => ['reason' => 'verification_failed', 'details' => ['A problem.']]]);
        $texts[] = $this->tool($token, 'get_task');

        // Every way the change can be while the worker still holds a token.
        foreach ([RunStatus::Verifying, RunStatus::Reviewing, RunStatus::NeedsUserDecision] as $status) {
            $run->update(['status' => $status]);
            $texts[] = $this->tool($token, 'check_status');
        }

        $fields = collect(['runs', 'feature_requests', 'projects', 'run_events'])
            ->flatMap(fn (string $table) => Schema::getColumnListing($table))
            ->filter(fn (string $column) => str_contains($column, '_') && ! str_ends_with($column, '_at'))
            ->unique()
            ->values();
        $this->assertContains('stop_reason', $fields, 'The lint knows the internal fields.');

        foreach ($texts as $text) {
            $this->assertNotSame('', trim((string) $text));
            $this->assertDoesNotMatchRegularExpression('/\b(builder|platform|control plane|confidence|probabilit\w*|scores?|capabilit\w*|context compiler|planner|reviewer|anthropic|openai)\b/i', $text);
            $this->assertDoesNotMatchRegularExpression('/\bEffects?\b/', $text);
            $this->assertStringNotContainsString('planner-model-7', $text);
            $this->assertStringNotContainsString('reviewer-model-7', $text);

            foreach ($fields as $field) {
                $this->assertDoesNotMatchRegularExpression('/\b'.preg_quote($field, '/').'\b/', $text, "Worker text names the internal field {$field}.");
            }
        }
    }

    /**
     * Call the task server as a worker's MCP client would.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function rpc(string $token, string $method, array $params = []): array
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params])
            ->assertOk()
            ->json();
    }

    /**
     * Get the text a tool answers with.
     */
    protected function tool(string $token, string $name): string
    {
        return collect($this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => (object) []])['result']['content'])
            ->pluck('text')
            ->implode("\n");
    }
}
