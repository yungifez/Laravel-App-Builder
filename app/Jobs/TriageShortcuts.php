<?php

namespace App\Jobs;

use App\Actions\Decisions\MakeDecisions;
use App\Actions\Runs\RecordModelUsage;
use App\Features\CodeShortcuts;
use App\Models\FeatureRequest;
use App\Projects\ProjectRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;

class TriageShortcuts implements ShouldQueue
{
    use Queueable;

    /**
     * A provider that is down is tried again a little later; nothing waits
     * on the answer.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(public FeatureRequest $featureRequest) {}

    /**
     * Ask the decision model whether each shortcut a kept change added is a
     * real problem, reading the whole file as it was kept, and log the
     * answers on the change's run. A shortcut whose line is no longer in
     * the kept file, because a change kept with it rewrote the line, is
     * logged as gone and not asked about.
     */
    public function handle(ProjectRepository $repository): void
    {
        $request = $this->featureRequest;
        $run = $request->latestRun;

        if (! config('builder.verification.shortcuts.triage.enabled') || MakeDecisions::providers() === [] || $request->commit_sha === null || $run === null) {
            return;
        }

        if ($run->events()->where('type', 'shortcuts_triaged')->exists()) {
            return;
        }

        $verification = $request->verifications()->whereNotNull('shortcuts')->latest('id')->first();
        $found = CodeShortcuts::found($verification?->shortcuts, $request->patch);

        if ($found === []) {
            return;
        }

        $triaged = [];

        foreach ($found as $shortcut) {
            $code = CodeShortcuts::code($shortcut, $request->patch);
            $file = $repository->show($request->project, $request->commit_sha, $shortcut['path']);
            $line = $code === null || $file === null ? null : $this->lineIn($file, $code, $shortcut['line']);

            if ($line === null) {
                $triaged[] = $shortcut + ['verdict' => 'gone'];

                continue;
            }

            $probability = $this->ask($request, $shortcut['rule'], $shortcut['path'], (string) $file, $line);

            $triaged[] = $shortcut + [
                'probability' => $probability,
                'verdict' => $probability >= (float) config('builder.verification.shortcuts.triage.threshold') ? 'real' : 'not_real',
            ];
        }

        $run->recordEvent('shortcuts_triaged', [
            'feature_request_id' => $request->id,
            'commit' => $request->commit_sha,
            'shortcuts' => $triaged,
        ]);
    }

    /**
     * Get the probability that the shortcut on the file's line is a real
     * problem, and log the call's tokens on the run.
     */
    protected function ask(FeatureRequest $request, string $rule, string $path, string $file, int $line): float
    {
        $response = Classification::of([
            'path' => $path,
            'line' => $line,
            'concern' => CodeShortcuts::concern($rule),
            'file' => $file,
        ])->question('real', new Boolean(
            'Read the whole file. Is the concern about the given line a real problem in this code, one worth changing?',
            [
                'true' => 'The line does what the concern says, and changing it would make the app faster or its errors visible.',
                'false' => 'The concern does not hold here: for example the list is small by design, the relation is already loaded, or the error is expected and handled or reported.',
            ],
        ))->timeout((int) config('builder.verification.shortcuts.triage.timeout'))->classify(MakeDecisions::providers());

        $this->recordUsage($request, $response);

        $answer = $response->answer('real');

        return $answer instanceof BooleanAnswer ? $answer->probability : 0.0;
    }

    /**
     * Log the classification's tokens on the change's run, as the other
     * model calls are.
     */
    protected function recordUsage(FeatureRequest $request, ClassificationResponse $response): void
    {
        $model = (string) $response->meta->model;
        $cost = RecordModelUsage::cost($model, $response->usage->inputTokens, $response->usage->outputTokens);

        $request->latestRun?->recordEvent('model_call', [
            'role' => 'triage',
            'provider' => $response->meta->provider,
            'model' => $model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'tool_calls' => 0,
            'cost_usd' => $cost,
            'cost_source' => $cost === null ? null : 'estimated',
        ]);
    }

    /**
     * Find the line of the file holding the code, the one nearest to where
     * the change put it, or null when the file no longer holds it.
     */
    protected function lineIn(string $file, string $code, int $near): ?int
    {
        $lines = array_keys(explode("\n", $file), $code, true);

        if ($lines === []) {
            return null;
        }

        usort($lines, fn (int $a, int $b) => abs($a + 1 - $near) <=> abs($b + 1 - $near));

        return $lines[0] + 1;
    }
}
