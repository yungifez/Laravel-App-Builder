<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

class SearchTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'search';
    }

    public function description(): Stringable|string
    {
        return 'Search the project\'s text files for an exact string. Returns matching lines as path:line:text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The exact text to find.')->required(),
            'directory' => $schema->string()->description('Directory to search in. Omit for the whole project.'),
        ];
    }
}
