<?php

namespace App\Mcp\Tools;

use App\Actions\Runs\TryWorkerChange;
use App\Enums\RunStatus;
use App\Runs\Drivers\WorkerDriver;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('try_change')]
#[Description('Run a command on your change on the app\'s own server, where the checks run, so you need nothing installed: php artisan (tests, make:*, route:list, migrate, tinker), vendor/bin/pest, pint, phpstan or npm run. Send your whole change as one patch, as for submit_change. Files the command writes come back as a patch to apply in your folder. This does not hand the change back.')]
class TryChange extends Tool
{
    public function __construct(
        protected WorkerTask $task,
        protected WorkerDriver $workers,
        protected TryWorkerChange $tryWorkerChange,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'patch' => ['present', 'nullable', 'string', 'max:'.((int) config('builder.agents.workers.max_patch_kb') * 1024)],
            'command' => ['required', 'array', 'min:1', 'max:50'],
            'command.*' => ['required', 'string', 'max:1000'],
        ], [
            'patch.max' => __('The change is too large to try in one patch.'),
        ]);

        $run = $this->task->run?->refresh();

        if ($run === null) {
            return Response::error(__('No change waits for you now. Call get_task first.'));
        }

        if ($run->status !== RunStatus::Implementing || $run->plan === null || $this->workers->submission($run) !== null) {
            return Response::error(__('Commands run only while the change waits for you. Call check_status to see where it is.'));
        }

        /** @var list<string> $command */
        $command = array_values($input['command']);
        $run->recordEvent('worker_tried', ['command' => implode(' ', $command)]);

        try {
            $result = $this->tryWorkerChange->handle($run, (string) ($input['patch'] ?? ''), $command);
        } catch (ValidationException $exception) {
            return Response::error(implode("\n", $exception->validator->errors()->all()));
        }

        return Response::text(implode("\n\n", array_filter([
            $result['timed_out']
                ? __('The command ran out of time after :seconds seconds.', ['seconds' => config('builder.agents.workers.try_seconds')])
                : __('Exit code :code.', ['code' => $result['exit_code']]),
            $result['output'] === '' ? null : $result['output'],
            $result['written'] === '' ? null : __("The command wrote these files. Apply this patch in your folder to keep them:\n\n:patch", ['patch' => $result['written']]),
        ])));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patch' => $schema->string()->description('Your whole change so far as a unified diff against the starting commit, with new files included, such as the output of `git add -N . && git diff --binary HEAD`. Empty to run on the starting code.')->required(),
            'command' => $schema->array()->items($schema->string())->description('The command and its arguments, one per item, such as ["php", "artisan", "test", "--filter=Waitlist"].')->required(),
        ];
    }
}
