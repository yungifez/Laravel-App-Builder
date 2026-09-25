<?php

namespace App\Actions\Runs;

use App\Enums\ModelRole;
use App\Models\Run;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\AgentResponse;

class RecordModelUsage
{
    /**
     * Log a model call's tokens on the run, once per invocation.
     */
    public function handle(Run $run, ModelRole $role, AgentResponse $response): void
    {
        DB::transaction(function () use ($run, $role, $response) {
            $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

            $recorded = $locked->events()
                ->where('type', 'model_call')
                ->where('data->invocation_id', $response->invocationId)
                ->exists();

            if ($recorded) {
                return;
            }

            $locked->recordEvent('model_call', [
                'invocation_id' => $response->invocationId,
                'role' => $role->value,
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'tool_calls' => $response->toolCalls->count(),
            ]);
        });
    }
}
