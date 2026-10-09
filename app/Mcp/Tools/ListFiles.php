<?php

namespace App\Mcp\Tools;

use App\Runs\Tools\ListFiles as ListsFiles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_files')]
#[Description('List the app\'s files on our side, with your change so far, when you have no folder of your own.')]
class ListFiles extends WorkerFileTool
{
    protected function tool(): string
    {
        return ListsFiles::class;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->common($schema),
            'directory' => $schema->string()->description('Only the files in this folder, such as "app/Models". All files when empty.'),
        ];
    }
}
