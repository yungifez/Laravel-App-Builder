<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

class ReadFileTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'read_file';
    }

    public function description(): Stringable|string
    {
        return 'Read a text file in the project. Returns its contents and sha256, which write_file needs to replace it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path relative to the project root.')->required(),
        ];
    }
}
