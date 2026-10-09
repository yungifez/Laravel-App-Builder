<?php

namespace App\Mcp\Tools;

use App\Runs\Tools\ReadFile as ReadsFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('read_file')]
#[Description('Read one of the app\'s files on our side, with your change so far, when you have no folder of your own. Gives its sha256, which write_file needs to replace it.')]
class ReadFile extends WorkerFileTool
{
    protected function tool(): string
    {
        return ReadsFile::class;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function say(array $result): string
    {
        return "path: {$result['path']}\nsha256: {$result['sha256']}\n\n{$result['contents']}";
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->common($schema),
            'path' => $schema->string()->description('The file\'s path from the app\'s root, such as "routes/web.php".')->required(),
        ];
    }
}
