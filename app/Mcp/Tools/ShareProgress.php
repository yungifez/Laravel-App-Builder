<?php

namespace App\Mcp\Tools;

use App\Enums\RunStatus;
use App\Runs\WorkerClaims;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('share_progress')]
#[Description('Tell the app\'s owner what you are doing now, in one plain sentence they understand, such as "Adding a page that lists each member\'s upcoming classes". They watch your work live. Call it when you start a new part of the change, alongside your next command; try_change takes the same sentence as `doing`.')]
class ShareProgress extends Tool
{
    public function __construct(
        protected WorkerTask $task,
        protected WorkerClaims $claims,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'doing' => ['required', 'string', 'max:300'],
        ]);

        [$run, $stop] = $this->claims->named($this->task, $request->get('task'));

        if ($stop !== null) {
            return Response::error($stop);
        }

        if ($run === null || $run->status !== RunStatus::Implementing) {
            return Response::error(__('No change waits for you now. Call get_task first.'));
        }

        // The owner reads it; the thread keeps only plain words from it.
        $run->recordEvent('worker_progress', ['text' => trim($input['doing'])]);

        return Response::text(__('Shared.'));
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
            'doing' => $schema->string()->description('What you are doing now, in one plain sentence, in the owner\'s words: no file, class or code names.')->required(),
        ];
    }
}
