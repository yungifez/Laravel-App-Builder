<?php

namespace App\Console\Commands;

use App\Actions\Features\RequestFeature;
use App\Actions\Runs\AwaitChange;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;

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
    public function handle(RequestFeature $requestFeature, AwaitChange $awaitChange): int
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
            $this->components->task("Change {$number}: {$prompt}", function () use ($requestFeature, $awaitChange, $project, $owner, $prompt, &$failure) {
                $failure = $awaitChange->handle($requestFeature->handle($project, $owner, $prompt), $owner, keep: true)['reason'];

                return $failure === null ? TaskResult::Success->value : TaskResult::Failure->value;
            });

            if ($failure !== null) {
                $this->components->error($failure);

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
