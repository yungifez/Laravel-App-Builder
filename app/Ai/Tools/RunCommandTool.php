<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

class RunCommandTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'run_command';
    }

    public function description(): Stringable|string
    {
        return 'Run one of the project\'s allowed commands by name, such as the tests, and get its exit code and output.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->enum(array_keys((array) config('builder.construction.commands')))->description('The command to run.')->required(),
        ];
    }
}
