<?php

namespace App\Runs\Tools;

use App\Runs\Contracts\MutatingTool;
use App\Runs\Exceptions\ToolRejected;
use App\Runs\ToolContext;

/**
 * Apply a unified diff with git. The diff's context lines must match the
 * current files exactly, so a patch written against stale contents is refused
 * and nothing changes.
 */
class ApplyPatch implements MutatingTool
{
    /**
     * Where the patch is staged inside the workspace, outside the project's files.
     */
    protected const PATCH_PATH = '.git/builder-operation.patch';

    public function rules(): array
    {
        return [
            'patch' => ['required', 'string', 'max:'.(int) config('builder.construction.limits.write_bytes')],
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $files = $this->stage($context, (string) $arguments['patch']);
        $check = $context->run(['git', 'apply', '--check', '--whitespace=nowarn', self::PATCH_PATH], 60);

        if ($check->exit_code !== 0) {
            throw new ToolRejected(__('The patch does not apply to the current files: :reason', [
                'reason' => $context->bound(trim($check->error_output)),
            ]));
        }

        $apply = $context->run(['git', 'apply', '--whitespace=nowarn', self::PATCH_PATH], 60);

        if ($apply->exit_code !== 0) {
            throw new ToolRejected(__('The patch could not be applied: :reason', ['reason' => $context->bound(trim($apply->error_output))]));
        }

        return ['files' => $files];
    }

    public function reconcile(ToolContext $context, array $arguments): ?array
    {
        $files = $this->stage($context, (string) $arguments['patch']);
        $reverse = $context->run(['git', 'apply', '--reverse', '--check', '--whitespace=nowarn', self::PATCH_PATH], 60);

        return $reverse->exit_code === 0 ? ['files' => $files] : null;
    }

    /**
     * Write the patch into the workspace and check every file it touches.
     *
     * @return list<string>
     *
     * @throws ToolRejected when the patch touches protected paths, adds symbolic links or cannot be read.
     */
    protected function stage(ToolContext $context, string $patch): array
    {
        $context->write(self::PATCH_PATH, $patch);

        $numstat = $context->run(['git', 'apply', '--numstat', '-z', self::PATCH_PATH], 60);
        $summary = $context->run(['git', 'apply', '--summary', self::PATCH_PATH], 60);

        if ($numstat->exit_code !== 0 || $summary->exit_code !== 0) {
            throw new ToolRejected(__('The patch is not a valid unified diff: :reason', [
                'reason' => $context->bound(trim($numstat->error_output ?: $summary->error_output)),
            ]));
        }

        if (str_contains($summary->output, '120000')) {
            throw new ToolRejected(__('Patches may not create symbolic links.'));
        }

        $files = $this->paths($numstat->output);

        if ($files === []) {
            throw new ToolRejected(__('The patch does not change any files.'));
        }

        foreach ($files as $file) {
            $context->writablePath($file);
        }

        return $files;
    }

    /**
     * Read the paths from `git apply --numstat -z` output, including both
     * sides of a rename.
     *
     * @return list<string>
     */
    protected function paths(string $numstat): array
    {
        $tokens = explode("\0", $numstat);
        $paths = [];

        for ($index = 0; $index < count($tokens); $index++) {
            if (! preg_match('/^(?:\d+|-)\t(?:\d+|-)\t(.*)$/s', $tokens[$index], $matches)) {
                continue;
            }

            if ($matches[1] !== '') {
                $paths[] = $matches[1];

                continue;
            }

            array_push($paths, $tokens[$index + 1] ?? '', $tokens[$index + 2] ?? '');
            $index += 2;
        }

        return array_values(array_unique(array_filter($paths, fn (string $path) => $path !== '')));
    }
}
