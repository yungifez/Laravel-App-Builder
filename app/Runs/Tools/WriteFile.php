<?php

namespace App\Runs\Tools;

use App\Runs\Contracts\MutatingTool;
use App\Runs\Exceptions\ToolRejected;
use App\Runs\ToolContext;

/**
 * Create or replace a file. Replacing a file requires the hash of the
 * contents the caller read, so an edit based on stale contents is refused.
 */
class WriteFile implements MutatingTool
{
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:1024'],
            'contents' => ['present', 'string', 'max:'.(int) config('builder.construction.limits.write_bytes')],
            'expected_sha256' => ['present', 'nullable', 'string', 'size:64'],
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $path = $context->writablePath((string) $arguments['path']);
        $expected = $arguments['expected_sha256'];

        if ($context->exists($path)) {
            if ($expected === null) {
                throw new ToolRejected(__('The file [:path] already exists; read it and pass its sha256 to replace it.', ['path' => $path]));
            }

            if (! hash_equals((string) $expected, hash('sha256', $context->read($path)))) {
                throw new ToolRejected(__('The file [:path] changed since it was read; read it again before editing.', ['path' => $path]));
            }
        } elseif ($expected !== null) {
            throw new ToolRejected(__('The file [:path] no longer exists; read the project again before editing.', ['path' => $path]));
        }

        $context->write($path, (string) $arguments['contents']);

        return $this->result($path, (string) $arguments['contents']);
    }

    public function reconcile(ToolContext $context, array $arguments): ?array
    {
        $path = $context->writablePath((string) $arguments['path']);
        $contents = (string) $arguments['contents'];

        if ($context->exists($path) && hash_equals(hash('sha256', $contents), hash('sha256', $context->read($path)))) {
            return $this->result($path, $contents);
        }

        return null;
    }

    /**
     * Describe the written file.
     *
     * @return array{path: string, sha256: string}
     */
    protected function result(string $path, string $contents): array
    {
        return ['path' => $path, 'sha256' => hash('sha256', $contents)];
    }
}
