<?php

namespace App\Mcp\Tools;

use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\Run;
use App\Runs\Drivers\WorkerDriver;
use App\Runs\WorkerClaims;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Sleep;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('check_status')]
#[Description('See where the change is: waiting for you, being checked, or sent back with problems to fix.')]
class CheckStatus extends Tool
{
    public function __construct(
        protected WorkerTask $task,
        protected WorkerClaims $claims,
        protected WorkerDriver $workers,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        [$run, $stop] = $this->claims->named($this->task, $request->get('task'));

        if ($stop !== null) {
            return Response::text($stop);
        }

        if ($run === null || ($this->task->wholeApp && $run->status->finished())) {
            $ended = $run ?? $this->lastEnded();

            return Response::text($ended === null
                ? __('No change waits for you now. Call get_task in a minute for the next one.')
                : __("No change waits for you now. The last one ended: :answer\n\nCall get_task in a minute for the next one.", ['answer' => $this->answer($ended)[0]]));
        }

        $run->recordEvent('worker_query', ['tool' => 'check_status']);

        // While the change is with us, hold the answer until something
        // changes, so the tool asks less often.
        [$answer, $withUs] = $this->answer($run);
        $checks = intdiv(max(0, (int) config('builder.agents.workers.status_wait_seconds')), 3);

        for ($check = 0; $withUs && $check < $checks; $check++) {
            Sleep::for(3)->seconds();

            [$latest, $withUs] = $this->answer($run->refresh());

            if ($latest !== $answer) {
                return Response::text($latest);
            }
        }

        return Response::text($answer);
    }

    /**
     * Say where the change is, and whether it is with us rather than with
     * the tool or finished.
     *
     * @return array{string, bool}
     */
    protected function answer(Run $run): array
    {
        return match ($run->status) {
            RunStatus::Queued, RunStatus::Planning => [__('The task is still being planned. Call get_task in a minute.'), true],
            RunStatus::Implementing => $this->implementing($run),
            RunStatus::Verifying => [__('Your change applied and is being checked. Call check_status again for the result.').$this->tryIt($run), true],
            RunStatus::Reviewing => [__('Your change passed the checks and is being reviewed. Call check_status again for the result.').$this->tryIt($run), true],
            RunStatus::NeedsUserDecision => [__('The change is waiting for the owner. Ask again later.'), false],
            RunStatus::Completed => [__('Your change passed its checks and review and is with the owner. There is nothing more to do on it.'), false],
            RunStatus::Cancelling, RunStatus::Cancelled => [__('The owner stopped this change. There is nothing more to do on it.'), false],
            RunStatus::Failed => [$this->failed($run), false],
        };
    }

    /**
     * Get the app's change the owner's tool picked up that ended last, while
     * it is news, so the tool hears how it ended rather than that nothing
     * waits.
     */
    protected function lastEnded(): ?Run
    {
        if ($this->task->project === null) {
            return null;
        }

        return Run::query()
            ->where('driver', 'worker')
            ->whereIn('status', [RunStatus::Completed, RunStatus::Cancelled, RunStatus::Failed])
            ->where('finished_at', '>=', now()->subMinutes(30))
            // Only a change the tool picked up: a question answered from the
            // plan never reached it.
            ->whereHas('events', fn ($query) => $query->where('type', 'worker_query'))
            ->whereHas('featureRequest', fn ($query) => $query->whereBelongsTo($this->task->project))
            ->latest('finished_at')
            ->first();
    }

    /**
     * Say that the app with the change runs, once it does, so the tool can
     * try it in a browser while it waits.
     */
    protected function tryIt(Run $run): string
    {
        return OpenPreview::preview($run) === null
            ? ''
            : "\n\n".__('Meanwhile the app runs with your change: call open_preview to try it in a browser.');
    }

    /**
     * Say why the change stopped, and that it was not the tool's doing when
     * it stopped on our side.
     */
    protected function failed(Run $run): string
    {
        $reason = $run->error ?? __('No reason was given.');

        return in_array($run->stop_reason, [StopReason::WorkerStopped, StopReason::ConstructionFailed], true)
            ? __("The change stopped on our side, not because of your work. The owner sees: \":reason\"\n\nThere is nothing more to do on it. The owner can try it again.", ['reason' => $reason])
            : __("The change stopped. The owner sees: \":reason\"\n\nThere is nothing more to do on it.", ['reason' => $reason]);
    }

    /**
     * Say what the change waits for while it is being written.
     *
     * @return array{string, bool}
     */
    protected function implementing(Run $run): array
    {
        $latest = $this->workers->latestSubmission($run);
        $refusal = $latest === null ? null : $this->workers->refusal($run, $latest);

        return match (true) {
            $latest !== null && $refusal === null => [__('Your change is being applied. Call check_status again for the result.'), true],
            $refusal !== null => [__("Your change did not apply to the starting commit:\n\n:reason\n\nMake it again on top of the starting commit in get_task, then call submit_change.", ['reason' => $refusal]), false],
            $run->feedback !== null => [__('The checks found problems. Call get_task for the problems to fix, then call submit_change with the whole change again.'), false],
            default => [__('Waiting for your change. Call submit_change when it is ready.'), false],
        };
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task code get_task gave you, when your tool writes every change of the app.'),
        ];
    }
}
