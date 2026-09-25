<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

class ApplyPatchTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'apply_patch';
    }

    protected function mutates(): bool
    {
        return true;
    }

    public function description(): Stringable|string
    {
        return 'Apply a unified diff (as produced by git diff) to the project. Its context lines must match the current files exactly. Pass the current workspace_revision from your latest tool result.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'patch' => $schema->string()->description('A unified diff with a/ and b/ path prefixes.')->required(),
            'expected_revision' => $schema->integer()->description('The workspace_revision from your latest tool result.')->required(),
        ];
    }
}
