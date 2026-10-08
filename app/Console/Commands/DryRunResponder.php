<?php

namespace App\Console\Commands;

use App\Evaluation\Suite;
use App\Features\PatchSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

#[Signature('eval:dry-respond {--once : Answer the waiting requests and stop}')]
#[Description('Answer evaluation hand-offs without a model, to check the harness end to end at no cost: coders apply the reference solution, reviewers reject only diffs that touch a sabotaged file')]
class DryRunResponder extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $directory = (string) config('evaluation.handoff.path');
        $suite = Suite::fromConfig();
        $sabotaged = [];

        foreach ($suite->sabotage() as $sabotage) {
            $sabotaged = [...$sabotaged, ...array_column(PatchSummary::files($suite->sabotagePatch($sabotage['patch'])), 'path')];
        }

        do {
            foreach (File::glob("{$directory}/*.request.json") as $file) {
                $request = json_decode(File::get($file), true);

                if (! is_array($request) || ! is_string($request['response'] ?? null) || File::exists($request['response'])) {
                    continue;
                }

                $prompt = is_string($request['prompt'] ?? null) ? $request['prompt'] : '';
                $task = $this->taskFor($suite, $prompt);

                $answer = match ($request['role'] ?? null) {
                    'planner' => $this->plan($suite, $task),
                    'coder', 'plain-coder' => $this->code($suite, $task, is_string($request['workspace'] ?? null) ? $request['workspace'] : ''),
                    'reviewer', 'generic-reviewer' => $this->review($prompt, $sabotaged, $request['role'] === 'reviewer'),
                    default => ['error' => 'Unknown role.'],
                };

                File::put($request['response'], (string) json_encode($answer, JSON_UNESCAPED_SLASHES));
                $this->line(($request['role'] ?? '?').' '.($task ?? '?'));
            }

            if (! $this->option('once')) {
                Sleep::for(1)->second();
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * Find the task whose request the prompt carries.
     */
    protected function taskFor(Suite $suite, string $prompt): ?string
    {
        foreach ($suite->taskKeys() as $task) {
            if (str_contains($prompt, $suite->task($task)['request'])) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(Suite $suite, ?string $task): array
    {
        $areas = $task === null ? [] : ($suite->task($task)['areas'] ?? []);

        return [
            'summary' => 'Dry run plan.',
            'acceptance_criteria' => ['The request is done.'],
            'assumptions' => ['Dry run: no decisions made.'],
            'tasks' => ['Apply the change.'],
            'understood_as' => 'Dry run',
            'current_behavior' => 'Unknown in a dry run.',
            'preserve' => array_map(fn (string $area) => ['area' => $area, 'statement' => "Existing {$area} behaviour keeps working."], $areas),
            'capabilities' => $areas,
            'steps' => [['key' => 'dry-run', 'kind' => 'behaviour', 'label' => 'Dry run', 'file' => 'unknown', 'symbol' => 'unknown', 'detail' => 'Dry run: no real step.']],
        ];
    }

    /**
     * Apply the task's reference solution in the workspace.
     *
     * @return array<string, mixed>
     */
    protected function code(Suite $suite, ?string $task, string $workspace): array
    {
        $reference = $task === null ? null : $suite->reference($task);

        if ($reference === null || $workspace === '') {
            return ['status' => 'failed', 'summary' => 'Dry run: no reference solution for this request.'];
        }

        $applied = Process::path($workspace)->input($reference)->run(['git', 'apply', '--whitespace=nowarn', '-'])->successful();

        return $applied
            ? ['status' => 'completed', 'summary' => 'Dry run: applied the reference solution.']
            : ['status' => 'failed', 'summary' => 'Dry run: the reference solution did not apply.'];
    }

    /**
     * Reject a diff that touches a sabotaged file; approve anything else.
     *
     * @param  list<string>  $sabotaged
     * @return array<string, mixed>
     */
    protected function review(string $prompt, array $sabotaged, bool $withChanges): array
    {
        $touched = array_values(array_filter($sabotaged, fn (string $file) => str_contains($prompt, "diff --git a/{$file} ")));
        $findings = array_map(fn (string $file) => ['severity' => 'blocking', 'summary' => 'Dry run: a sabotaged file changed.', 'file' => $file], $touched);

        return [
            'approved' => $touched === [],
            'summary' => 'Dry run review.',
            'findings' => $findings,
            ...($withChanges ? ['changes' => []] : []),
        ];
    }
}
