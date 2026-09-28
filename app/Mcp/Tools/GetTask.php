<?php

namespace App\Mcp\Tools;

use App\Actions\Runs\WriteBrief;
use App\Models\Run;
use App\Runs\Plan;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_task')]
#[Description('Get the task: what to build, what must keep working, and how the change is checked. Ask again after a check, for the problems to fix.')]
class GetTask extends Tool
{
    public function __construct(
        protected WorkerTask $task,
        protected WriteBrief $writeBrief,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $run = $this->task->run->refresh();
        $run->recordEvent('worker_query', ['tool' => 'get_task']);

        if ($run->plan === null) {
            return Response::text(__('The task is still being planned. Ask again in a minute.'));
        }

        return Response::text($this->writeBrief->handle($run, Plan::fromArray($run->plan))."\n\n".$this->handBack($run));
    }

    /**
     * Say where the change starts and how to hand it back. The starting
     * point is a commit of the owner's own repository, which the worker can
     * check out; our workspace's commits mean nothing outside it.
     */
    protected function handBack(Run $run): string
    {
        $featureRequest = $run->featureRequest;
        $base = $featureRequest->base_revision;
        $unkept = count($featureRequest->lineage()) > 1;

        return implode("\n\n", array_filter([
            '## Hand the change back',
            $base === null ? null : __('Start from commit :base of the app.', ['base' => $base]),
            $unkept ? __('Earlier changes that are not kept yet are applied under yours. Change only what this task asks.') : null,
            __('When you are done, call submit_change with the whole change as one patch, such as the output of `git add -N . && git diff --binary :base`, and a short summary. Then call check_status to see how the checks went.', ['base' => $base ?? 'HEAD']),
        ]));
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
