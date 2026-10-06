<?php

namespace App\Mcp\Tools;

use App\Runs\Tools\WriteFile as WritesFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('write_file')]
#[Description('Make or replace one of the app\'s files on our side, when you have no folder of your own. It becomes part of your change, which try_change and submit_change use when you send them no patch.')]
class WriteFile extends WorkerFileTool
{
    protected function tool(): string
    {
        return WritesFile::class;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->common($schema),
            'path' => $schema->string()->description('The file\'s path from the app\'s root, such as "app/Models/Booking.php".')->required(),
            'contents' => $schema->string()->description('The whole new contents of the file.')->required(),
            'expected_sha256' => $schema->string()->description('To replace a file: the sha256 read_file gave for it, so a file changed since is not overwritten. Leave it out for a new file.'),
            'doing' => $schema->string()->description('When you start a new part of the change: what you are doing now, in one plain sentence in the owner\'s words, as for share_progress.'),
        ];
    }
}
