<?php

namespace App\Runs\Tools;

use App\Runs\Contracts\Tool;
use App\Runs\Exceptions\ToolFailed;
use App\Runs\ToolContext;

/**
 * List the project's files, skipping anything its .gitignore excludes.
 */
class ListFiles implements Tool
{
    public function rules(): array
    {
        return [
            'directory' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $directory = blank($arguments['directory'] ?? null) ? '.' : $context->readablePath((string) $arguments['directory']);
        $command = $context->run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--', $directory], 60);

        if ($command->exit_code !== 0) {
            throw new ToolFailed(trim($command->error_output) ?: __('The files could not be listed.'));
        }

        $files = array_values(array_filter(explode("\n", $command->output), fn (string $line) => $line !== '' && ! str_starts_with($line, '…')));
        $limit = (int) config('builder.construction.limits.list_entries');

        return [
            'files' => array_slice($files, 0, $limit),
            'truncated' => count($files) > $limit || str_starts_with($command->output, '…'),
        ];
    }
}
