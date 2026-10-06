<?php

namespace App\Mcp\Tools;

use App\Actions\Runs\UseWorkerFiles;
use App\Enums\RunStatus;
use App\Runs\Contracts\Tool as FileTool;
use App\Runs\Drivers\WorkerDriver;
use App\Runs\Exceptions\ToolFailed;
use App\Runs\Exceptions\ToolRejected;
use App\Runs\WorkerClaims;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * One of our own coder's file tools, for a worker with no folder of its
 * own, as in a chat in the Claude app: it reads and changes the app on our
 * side, where try_change and submit_change then find the change.
 */
abstract class WorkerFileTool extends Tool
{
    public function __construct(
        protected WorkerTask $task,
        protected WorkerClaims $claims,
        protected WorkerDriver $workers,
        protected UseWorkerFiles $useWorkerFiles,
    ) {}

    /**
     * Get our coder's tool this one uses.
     *
     * @return class-string<FileTool>
     */
    abstract protected function tool(): string;

    /**
     * Say what the tool found or did.
     *
     * @param  array<string, mixed>  $result
     */
    protected function say(array $result): string
    {
        return (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        [$run, $stop] = $this->claims->named($this->task, $request->get('task'));

        if ($stop !== null) {
            return Response::error($stop);
        }

        if ($run === null) {
            return Response::error(__('No change waits for you now. Call get_task first.'));
        }

        if ($run->status !== RunStatus::Implementing || $run->plan === null || $this->workers->submission($run) !== null) {
            return Response::error(__('The files open only while the change waits for you. Call check_status to see where it is.'));
        }

        /** @var FileTool $tool */
        $tool = app($this->tool());

        try {
            // What is left out counts as empty, as a hash for a new file.
            $arguments = Validator::make([...array_fill_keys(array_keys($tool->rules()), null), ...$request->all()], $tool->rules())->validate();

            if (filled($request->get('doing'))) {
                $run->recordEvent('worker_progress', ['text' => trim((string) $request->get('doing'))]);
            }

            return Response::text($this->say($this->useWorkerFiles->handle($run, $tool, $arguments)));
        } catch (ValidationException $exception) {
            return Response::error(implode("\n", $exception->validator->errors()->all()));
        } catch (ToolRejected|ToolFailed $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /**
     * The input every file tool takes.
     *
     * @return array<string, Type>
     */
    protected function common(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task code get_task gave you, when your tool writes every change of the app.'),
        ];
    }
}
