<?php

namespace App\Mcp\Tools;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use App\Runs\Drivers\WorkerDriver;
use App\Runs\WorkerClaims;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('submit_change')]
#[Description('Hand the change back: the whole change as one patch against the starting commit in the task, and a short summary. The change is then checked; call check_status to see how it went.')]
class SubmitChange extends Tool
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
        $input = $request->validate([
            'patch' => ['required', 'string', 'max:'.((int) config('builder.agents.workers.max_patch_kb') * 1024)],
            'summary' => ['required', 'string', 'max:2000'],
        ], [
            'patch.max' => __('The change is too large to hand back in one patch.'),
        ]);

        [$task, $stop] = $this->claims->named($this->task, $request->get('task'));

        if ($stop !== null) {
            return Response::error($stop);
        }

        if ($task === null) {
            return Response::error(__('No change waits for you now. Call get_task first.'));
        }

        $refused = DB::transaction(function () use ($input, $task) {
            $run = Run::query()->lockForUpdate()->findOrFail($task->id);
            $refused = $this->refuse($run);

            if ($refused === null) {
                $run->recordEvent('worker_submitted', ['patch' => $input['patch'], 'summary' => $input['summary']]);
            }

            return $refused;
        });

        if ($refused !== null) {
            return Response::error($refused);
        }

        ExecuteRun::dispatch($task);

        return Response::text(__('Received. Your change is being applied and checked. Call check_status to see how it goes.'));
    }

    /**
     * Say why the change cannot be handed back now, if it cannot.
     */
    protected function refuse(Run $run): ?string
    {
        $reason = match (true) {
            $run->driver !== 'worker' => __('This change is being written already. There is nothing to hand back.'),
            $run->status === RunStatus::Queued, $run->status === RunStatus::Planning, $run->plan === null => __('The task is still being planned. Call get_task in a minute.'),
            $run->status !== RunStatus::Implementing => __('The change is not waiting for a patch now. Call check_status to see where it is.'),
            $this->workers->submission($run) !== null => __('Your last change is still being applied. Call check_status to see how it goes.'),
            default => null,
        };

        return is_string($reason) ? $reason : null;
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
            'patch' => $schema->string()->description('The whole change as a unified diff against the starting commit, with new files included.')->required(),
            'summary' => $schema->string()->description('What you changed and why, in a few sentences.')->required(),
        ];
    }
}
