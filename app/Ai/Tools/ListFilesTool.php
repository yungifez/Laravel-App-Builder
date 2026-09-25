<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

class ListFilesTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'list_files';
    }

    public function description(): Stringable|string
    {
        return 'List the project\'s files (respecting .gitignore), optionally inside one directory.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'directory' => $schema->string()->description('Directory relative to the project root. Omit for the whole project.'),
        ];
    }
}
