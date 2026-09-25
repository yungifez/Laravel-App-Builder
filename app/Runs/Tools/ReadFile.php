<?php

namespace App\Runs\Tools;

use App\Runs\Contracts\Tool;
use App\Runs\Exceptions\ToolFailed;
use App\Runs\ToolContext;

/**
 * Read a text file, returning its contents and the hash that edits must name.
 */
class ReadFile implements Tool
{
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:1024'],
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $path = $context->readablePath((string) $arguments['path']);
        $contents = $context->read($path);
        $limit = (int) config('builder.construction.limits.read_bytes');

        if (strlen($contents) > $limit) {
            throw new ToolFailed(__('The file [:path] is larger than :limit bytes.', ['path' => $path, 'limit' => $limit]));
        }

        return [
            'path' => $path,
            'contents' => $contents,
            'sha256' => hash('sha256', $contents),
        ];
    }
}
