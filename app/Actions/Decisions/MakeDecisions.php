<?php

namespace App\Actions\Decisions;

use App\Actions\Runs\RecordModelUsage;
use App\Models\Decision;
use App\Models\FeatureRequest;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use LogicException;

class MakeDecisions
{
    /**
     * Ask the decision model about the request in one call and keep each
     * answer. Only the owner's own words are sent; nothing about how
     * changes are made goes with them.
     *
     * @return list<Decision>
     */
    public function handle(FeatureRequest $featureRequest): array
    {
        $providers = self::providers();

        if ($providers === []) {
            return [];
        }

        $started = hrtime(true);
        $response = Classification::of($featureRequest->prompt)
            ->questions($this->questions())
            ->timeout((int) config('builder.decisions.timeout'))
            ->classify($providers);
        $latency = (int) round((hrtime(true) - $started) / 1_000_000);

        $cost = RecordModelUsage::cost((string) $response->meta->model, $response->usage->inputTokens, $response->usage->outputTokens);

        $featureRequest->update(['decision_model_calls' => [...$featureRequest->decision_model_calls ?? [], [
            'provider' => (string) $response->meta->provider,
            'model' => $response->meta->model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cost_usd' => $cost,
            'cost_source' => $cost === null ? null : 'estimated',
            'at' => now()->toIso8601String(),
        ]]]);

        // The one call answers every question, so each answer carries an
        // equal share of its cost.
        $share = $cost === null ? null : round($cost / max(count($response->answers), 1), 6);
        $decisions = [];

        foreach ($response->answers as $name => $answer) {
            $decisions[] = $featureRequest->decisions()->updateOrCreate(['name' => $name], [
                ...$this->describe($answer),
                'driver' => (string) $response->meta->provider,
                'model' => $response->meta->model,
                'fallback' => (string) $response->meta->provider !== $providers[0],
                'threshold' => (float) (config("builder.decisions.thresholds.{$name}") ?? 1),
                'acted' => false,
                'latency_ms' => $latency,
                'cost_usd' => $share,
            ]);
        }

        return $decisions;
    }

    /**
     * Get the configured decision providers that have a key, in order.
     *
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_values(array_filter(
            (array) config('builder.decisions.providers'),
            fn (mixed $provider) => is_string($provider) && filled(config("ai.providers.{$provider}.key")),
        ));
    }

    /**
     * The questions asked about every request (architecture §26.9).
     *
     * @return array<string, Question>
     */
    protected function questions(): array
    {
        return [
            'complexity' => new Choice('How much work is this request to a web application?', [
                'trivial' => 'A small wording, style or display change in one place.',
                'normal' => 'A feature or change to a few parts of the application.',
                'substantial' => 'A change across many parts, new data, or a new way of working.',
            ]),
            'question' => new Boolean('Is the person asking a question about their application, rather than asking for a change?'),
            'permissions' => new Boolean('Does the request change who may see or do something?'),
            'persisted_data' => new Boolean('Does the request need new or changed stored data?'),
            'destructive' => new Boolean('Does the request remove or delete data, or remove a feature?'),
        ];
    }

    /**
     * Turn an answer into a choice, its probabilities and a confidence. A
     * yes/no answer is sure to the extent it is far from a coin toss.
     *
     * @return array{choice: string, probabilities: array<string, float>, confidence: float}
     */
    protected function describe(Answer $answer): array
    {
        if ($answer instanceof BooleanAnswer) {
            $yes = round($answer->probability, 4);

            return [
                'choice' => $yes >= 0.5 ? 'yes' : 'no',
                'probabilities' => ['yes' => $yes, 'no' => round(1 - $yes, 4)],
                'confidence' => max($yes, 1 - $yes),
            ];
        }

        if (! $answer instanceof ChoiceAnswer) {
            throw new LogicException('Only yes/no and choice questions are asked.');
        }

        return [
            'choice' => $answer->choice,
            'probabilities' => array_map(floatval(...), $answer->probabilities),
            'confidence' => (float) ($answer->confidence ?? $answer->probabilities[$answer->choice] ?? 0.0),
        ];
    }
}
