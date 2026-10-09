<?php

namespace App\Mcp\Tools;

use App\Runs\Tools\SearchFiles as SearchesFiles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('search_files')]
#[Description('Find text in the app\'s files on our side, with your change so far, when you have no folder of your own. Gives each line it is on.')]
class SearchFiles extends WorkerFileTool
{
    protected function tool(): string
    {
        return SearchesFiles::class;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->common($schema),
            'query' => $schema->string()->description('The text to find.')->required(),
            'directory' => $schema->string()->description('Only in this folder, such as "app". Everywhere when empty.'),
        ];
    }
}
