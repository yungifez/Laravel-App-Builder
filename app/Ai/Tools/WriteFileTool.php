<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

class WriteFileTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'write_file';
    }

    protected function mutates(): bool
    {
        return true;
    }

    public function description(): Stringable|string
    {
        return 'Create a file, or replace one you have read. To replace a file, pass the sha256 read_file returned; to create one, pass null. Pass the current workspace_revision from your latest tool result.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path relative to the project root.')->required(),
            'contents' => $schema->string()->description('The complete new contents of the file.')->required(),
            'expected_sha256' => $schema->string()->nullable()->description('The sha256 of the contents you read, or null for a new file.')->required(),
            'expected_revision' => $schema->integer()->description('The workspace_revision from your latest tool result.')->required(),
        ];
    }
}
