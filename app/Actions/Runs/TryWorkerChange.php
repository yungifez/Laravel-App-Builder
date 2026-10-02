<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Context\ProjectNotes;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\Agents\RunnerAgent;
use App\Runs\Exceptions\ConstructionFailed;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Run one command on a worker's change in our own workspace, as the checks
 * will. The owner's tool then needs nothing installed, and what passes here
 * passes there (architecture §11, "Workers").
 *
 * The worker's folder keeps the change: each try starts from the baseline
 * with the worker's whole patch, and the workspace is put back after it.
 * Files the command writes, such as those a generator makes, come back as
 * a patch for the worker to apply in its own folder.
 */
class TryWorkerChange
{
    protected const PATCH = RunnerAgent::TASK_DIRECTORY.'/try.patch';

    public function __construct(
        private RunWorkspaceCommand $runWorkspaceCommand,
        private ExtractCandidateChange $extractCandidateChange,
    ) {}

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int|null, timed_out: bool, output: string, written: string}
     *
     * @throws ValidationException when the command is not allowed, or the patch does not apply.
     */
    public function handle(Run $run, string $patch, array $command): array
    {
        if (! self::allowed($command)) {
            throw ValidationException::withMessages(['command' => __('This command cannot run here. Allowed commands start with: :list.', ['list' => implode('; ', array_map(fn (array $prefix) => implode(' ', $prefix), self::prefixes()))])]);
        }

        $workspace = $run->workspace ?? throw ValidationException::withMessages(['command' => __('The change has no copy to run commands in yet. This is our fault. Try again in a minute.')]);
        $lock = self::lock($run);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['command' => __('Another command is running on this change. Try again when it ends.')]);
        }

        try {
            $baseline = $this->extractCandidateChange->baseline($workspace);
            $this->reset($workspace, $baseline);

            if (trim($patch) !== '') {
                $this->apply($workspace, $patch);
            }

            // What the patch holds, so only what the command writes is new.
            $this->git($workspace, ['add', '--all']);

            $result = $this->runWorkspaceCommand->handle($workspace, $command, (int) config('builder.agents.workers.try_seconds'));

            $this->git($workspace, ['add', '--intent-to-add', '--all']);
            $written = $this->runWorkspaceCommand->handle($workspace, ['git', 'diff', '--binary'], 60)->output;

            return [
                'exit_code' => $result->exit_code,
                'timed_out' => $result->timed_out,
                'output' => Str::limit(trim($result->output."\n".$result->error_output), 20_000, '…', preserveWords: false),
                'written' => strlen($written) > (int) config('builder.agents.workers.max_patch_kb') * 1024 ? '' : $written,
            ];
        } finally {
            rescue(fn () => $this->reset($workspace, $this->extractCandidateChange->baseline($workspace)), report: false);
            $lock->release();
        }
    }

    /**
     * The lock a try holds, which applying a handed-back change waits for,
     * since both use the same workspace.
     */
    public static function lock(Run $run): Lock
    {
        return Cache::lock("runs:{$run->id}:worker-workspace", (int) config('builder.agents.workers.try_seconds') + 120);
    }

    /**
     * Determine whether the command starts with one of the allowed ones.
     *
     * @param  list<string>  $command
     */
    public static function allowed(array $command): bool
    {
        foreach (self::prefixes() as $prefix) {
            if (array_slice($command, 0, count($prefix)) === $prefix && count($command) >= count($prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<list<string>>
     */
    public static function prefixes(): array
    {
        /** @var list<list<string>> */
        return config('builder.agents.workers.try_commands');
    }

    /**
     * Put the workspace back to the baseline. Ignored files (installed
     * packages, the app's settings) stay, so the next try is quick.
     */
    protected function reset(Workspace $workspace, string $baseline): void
    {
        $this->git($workspace, ['reset', '-q', '--hard', $baseline]);
        $this->git($workspace, ['clean', '-fdq']);
    }

    /**
     * @throws ValidationException when the patch does not apply.
     */
    protected function apply(Workspace $workspace, string $patch): void
    {
        app(WorkspaceManager::class)->driver($workspace->driver)->writeFile((string) $workspace->driver_id, self::PATCH, $patch);

        // The notes are ours: a worker's copy has none, as when it hands in.
        $result = $this->runWorkspaceCommand->handle($workspace, ['git', 'apply', '--3way', '--whitespace=nowarn', '--exclude='.ProjectNotes::directory().'/*', self::PATCH], 120);
        $this->runWorkspaceCommand->handle($workspace, ['rm', '-f', self::PATCH], 30);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw ValidationException::withMessages(['patch' => __("Your patch did not apply to the starting commit:\n\n:reason", ['reason' => Str::limit(str_replace(self::PATCH, 'the patch', trim($result->error_output ?: $result->output)), 1000)])]);
        }
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws ConstructionFailed
     */
    protected function git(Workspace $workspace, array $arguments): void
    {
        $result = $this->runWorkspaceCommand->handle($workspace, ['git', ...$arguments], 120);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw new ConstructionFailed(__('The change\'s copy could not be prepared. This is our fault. Try again in a minute.'));
        }
    }
}
