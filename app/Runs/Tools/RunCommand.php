<?php

namespace App\Runs\Tools;

use App\Runs\Contracts\Tool;
use App\Runs\ToolContext;
use Illuminate\Validation\Rule;

/**
 * Run one of the commands the platform allows by name, such as the tests.
 * Callers cannot run arbitrary commands.
 */
class RunCommand implements Tool
{
    public function rules(): array
    {
        return [
            'command' => ['required', 'string', Rule::in(array_keys((array) config('builder.construction.commands')))],
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        /** @var array{command: list<string>, timeout: int} $definition */
        $definition = config('builder.construction.commands.'.$arguments['command']);
        $command = $context->run($definition['command'], $definition['timeout']);

        return [
            'command' => $arguments['command'],
            'exit_code' => $command->exit_code,
            'timed_out' => $command->timed_out,
            'duration_ms' => $command->duration_ms,
            'output' => $context->bound(trim($command->output."\n".$command->error_output), keepEnd: true),
        ];
    }
}
