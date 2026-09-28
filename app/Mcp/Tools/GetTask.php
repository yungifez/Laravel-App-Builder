<?php

namespace App\Mcp\Tools;

use App\Actions\Runs\WriteBrief;
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

        $brief = $this->writeBrief->handle($run, Plan::fromArray($run->plan));
        $base = $run->workspace?->baseline_commit;

        return Response::text($base === null ? $brief : "{$brief}\n\n## Starting point\n\nMake the change on top of commit {$base}.");
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
