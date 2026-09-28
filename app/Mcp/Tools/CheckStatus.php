<?php

namespace App\Mcp\Tools;

use App\Enums\RunStatus;
use App\Runs\Drivers\WorkerDriver;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
        protected WorkerDriver $workers,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $run = $this->task->run->refresh();
        $run->recordEvent('worker_query', ['tool' => 'check_status']);

        return Response::text(match ($run->status) {
            RunStatus::Queued, RunStatus::Planning => __('The task is still being planned. Call get_task in a minute.'),
            RunStatus::Implementing => $this->implementing(),
            RunStatus::Verifying => __('Your change applied and is being checked. Ask again in a few minutes.'),
            RunStatus::Reviewing => __('Your change passed the checks and is being reviewed. Ask again in a few minutes.'),
            RunStatus::NeedsUserDecision => __('The change is waiting for the owner. Ask again later.'),
            default => __('The change was stopped. There is nothing more to do.'),
        });
    }

    /**
     * Say what the change waits for while it is being written.
     */
    protected function implementing(): string
    {
        $run = $this->task->run;
        $latest = $this->workers->latestSubmission($run);
        $refusal = $latest === null ? null : $this->workers->refusal($run, $latest);

        return match (true) {
            $latest !== null && $refusal === null => __('Your change is being applied. Ask again in a minute.'),
            $refusal !== null => __("Your change did not apply to the starting commit:\n\n:reason\n\nMake it again on top of the starting commit in get_task, then call submit_change.", ['reason' => $refusal]),
            $run->feedback !== null => __('The checks found problems. Call get_task for the problems to fix, then call submit_change with the whole change again.'),
            default => __('Waiting for your change. Call submit_change when it is ready.'),
        };
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
