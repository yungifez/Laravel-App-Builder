<?php

namespace App\Runs\Tools;

use App\Runs\Contracts\Tool;
use App\Runs\Exceptions\ToolFailed;
use App\Runs\ToolContext;

/**
 * Search the project's text files for a fixed string.
 */
class SearchFiles implements Tool
{
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:500'],
            'directory' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $directory = blank($arguments['directory'] ?? null) ? '.' : $context->readablePath((string) $arguments['directory']);
        $command = $context->run([
            'git', 'grep', '--untracked', '--line-number', '-I', '--no-color', '--fixed-strings',
            '-e', (string) $arguments['query'], '--', $directory,
        ], 60);

        // git grep exits with 1 when nothing matches.
        if ($command->exit_code > 1) {
            throw new ToolFailed(trim($command->error_output) ?: __('The search could not run.'));
        }

        $matches = array_values(array_filter(explode("\n", $command->output), fn (string $line) => $line !== '' && ! str_starts_with($line, '…')));
        $limit = (int) config('builder.construction.limits.search_matches');

        return [
            'matches' => array_map($context->bound(...), array_slice($matches, 0, $limit)),
            'truncated' => count($matches) > $limit || str_starts_with($command->output, '…'),
        ];
    }
}
