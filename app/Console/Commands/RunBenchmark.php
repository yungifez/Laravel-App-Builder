<?php

namespace App\Console\Commands;

use App\Actions\Changes\AcceptChange;
use App\Actions\Features\RequestFeature;
use App\Actions\Runs\AnswerRunQuestion;
use App\Enums\RunStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('builder:benchmark {project : The project, by id} {--changes=1 : How many of the next changes to make}')]
#[Description('Make the next changes of the Evolution Benchmark in a project, as an owner would')]
class RunBenchmark extends Command
{
    /**
     * Ask for the next changes of the fixed sequence, one at a time, and
     * keep each one whose run completes (direction 21 §15). A question the
     * builder asks gets its recommended answer, as an owner who trusts it
     * would give. A change that fails stops the benchmark: the ones after
     * it build on it. `builder:evolution` then shows how the changes went.
     */
    public function handle(RequestFeature $requestFeature, AnswerRunQuestion $answerRunQuestion, AcceptChange $acceptChange): int
    {
        $project = Project::query()->find($this->argument('project'));

        if ($project === null) {
            $this->components->error('No project has that id.');

            return self::FAILURE;
        }

        /** @var list<string> $sequence */
        $sequence = config('builder.benchmark.changes');
        $owner = $project->owner;
        $kept = $project->featureRequests()->whereNotNull('commit_sha')->whereNull('reverted_at')->pluck('prompt')->all();
        $next = array_values(array_filter($sequence, fn (string $prompt) => ! in_array($prompt, $kept, true)));

        if ($next === []) {
            $this->components->info('Every change of the benchmark is kept in this project.');

            return self::SUCCESS;
        }

        foreach (array_slice($next, 0, max(1, (int) $this->option('changes'))) as $prompt) {
            $number = array_search($prompt, $sequence, true) + 1;
            $failure = null;
            $this->components->task("Change {$number}: {$prompt}", function () use ($requestFeature, $answerRunQuestion, $acceptChange, $project, $owner, $prompt, &$failure) {
                $failure = $this->make($requestFeature, $answerRunQuestion, $acceptChange, $project, $owner, $prompt);

                return $failure === null;
            });

            if ($failure !== null) {
                $this->components->error($failure);

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Make one change and keep it. Return why it could not be kept, or null.
     */
    protected function make(RequestFeature $requestFeature, AnswerRunQuestion $answerRunQuestion, AcceptChange $acceptChange, Project $project, User $owner, string $prompt): ?string
    {
        $request = $requestFeature->handle($project, $owner, $prompt);
        $deadline = now()->addSeconds((int) config('builder.benchmark.wait'));

        while (now()->lessThan($deadline)) {
            $request->refresh();
            $run = $request->latestRun;

            if ($run?->status === RunStatus::NeedsUserDecision && $run->question !== null) {
                $answerRunQuestion->handle($request, $owner, $run->question['recommended'] ?? $run->question['options'][0] ?? null);
            } elseif ($run?->status === RunStatus::Completed) {
                $kept = $acceptChange->handle($request, $owner);

                if ($kept->commit_sha !== null) {
                    return null;
                }

                // The app moved on while the change was made: it is built
                // again on top, and that one is kept instead.
                $request = $kept;
            } elseif ($run !== null && in_array($run->status, [RunStatus::Failed, RunStatus::Cancelled], true)) {
                return "It stopped: {$run->status->value}. ".($run->error ?? $request->error ?? '');
            }

            Sleep::for(10)->seconds();
        }

        return 'It took longer than the benchmark waits for one change.';
    }
}
