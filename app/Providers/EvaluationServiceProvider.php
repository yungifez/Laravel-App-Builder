<?php

namespace App\Providers;

use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\GenericReviewer;
use App\Evaluation\Handoff;
use App\Evaluation\HandoffCodingAgent;
use App\Runs\Agents\CodingAgentManager;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Hands every model call to an outside responder when the evaluation's
 * hand-off directory is set (config/evaluation.php). Off by default, and
 * refused in production.
 */
class EvaluationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $handoff = Handoff::fromConfig();

        if ($handoff === null) {
            return;
        }

        if ($this->app->environment('production')) {
            throw new RuntimeException('The evaluation hand-off (BUILDER_EVAL_HANDOFF) must not be enabled in production.');
        }

        foreach (['planner' => FeaturePlanner::class, 'reviewer' => ChangeReviewer::class, 'generic-reviewer' => GenericReviewer::class] as $role => $agent) {
            $instance = new $agent;

            $agent::fake(fn (string $prompt) => $handoff->ask($role, [
                'instructions' => (string) $instance->instructions(),
                'schema' => (new ObjectType($instance->schema(new JsonSchemaTypeFactory)))->toArray(),
                'prompt' => $prompt,
            ]));
        }

        $this->app->afterResolving(CodingAgentManager::class, function (CodingAgentManager $agents) use ($handoff) {
            foreach (['claude', 'codex'] as $adapter) {
                $agents->extend($adapter, fn () => new HandoffCodingAgent($handoff, $adapter));
            }
        });
    }
}
