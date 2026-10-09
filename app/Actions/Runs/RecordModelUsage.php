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

            $cost = self::cost((string) $response->meta->model, $response->usage->inputTokens, $response->usage->outputTokens);

            $locked->recordEvent('model_call', [
                'invocation_id' => $response->invocationId,
                'role' => $role->value,
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'tool_calls' => $response->toolCalls->count(),
                'cost_usd' => $cost,
                // Our estimate from config prices; the provider did not report it.
                'cost_source' => $cost === null ? null : 'estimated',
            ]);
        });
    }

    /**
     * Price a call from the configured prices, or null when the model has none.
     * Cached input is part of the input and costs "cached_input" when the
     * model has that price; without one it is priced as fresh input, so the
     * cost is never under-counted.
     */
    public static function cost(string $model, int $inputTokens, int $outputTokens, int $cachedInputTokens = 0): ?float
    {
        $price = ((array) config('builder.prices'))[$model] ?? null;

        if (! is_array($price) || ! is_numeric($price['input'] ?? null) || ! is_numeric($price['output'] ?? null)) {
            return null;
        }

        $cached = min(max($cachedInputTokens, 0), $inputTokens);
        $cachedPrice = is_numeric($price['cached_input'] ?? null) ? (float) $price['cached_input'] : (float) $price['input'];

        return round((($inputTokens - $cached) * (float) $price['input'] + $cached * $cachedPrice + $outputTokens * (float) $price['output']) / 1_000_000, 6);
    }
}
