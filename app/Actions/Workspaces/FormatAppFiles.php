<?php

namespace App\Actions\Workspaces;

use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Throwable;

class FormatAppFiles
{
    public function __construct(
        private WorkspaceManager $workspaces,
        private RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    /**
     * Get files as the app's own formatters write them, run in a workspace
     * that has the app's tools. The files are formatted as copies in a
     * directory of ours, so the app's files, and a build watching them,
     * are not touched. A file no formatter takes, or one whose formatter
     * fails, comes back as it was.
     *
     * @param  array<string, string>  $files  Contents, by path in the app
     * @return array<string, string>
     */
    public function handle(Workspace $workspace, array $files): array
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $root = Config::string('builder.preview.watch.directory').'/format/'.Str::random(12);

        try {
            foreach (Config::array('builder.construction.formatters') as $formatter) {
                if (! is_array($formatter) || ! is_array($formatter['command'] ?? null)) {
                    continue;
                }

                $extensions = (array) ($formatter['extensions'] ?? []);
                $matching = array_filter($files, fn (string $path) => in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true), ARRAY_FILTER_USE_KEY);

                if ($matching === []) {
                    continue;
                }

                foreach ($matching as $path => $contents) {
                    $driver->writeFile((string) $workspace->driver_id, "{$root}/{$path}", $contents);
                }

                $result = $this->runWorkspaceCommand->handle(
                    $workspace,
                    [...array_values(array_map(strval(...), $formatter['command'])), ...array_map(fn (string $path) => "{$root}/{$path}", array_keys($matching))],
                    (int) ($formatter['timeout'] ?? 120),
                );

                if ($result->exit_code !== 0 || $result->timed_out) {
                    continue;
                }

                foreach (array_keys($matching) as $path) {
                    $files[$path] = $driver->readFile((string) $workspace->driver_id, "{$root}/{$path}");
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            rescue(fn () => $this->runWorkspaceCommand->handle($workspace, ['rm', '-rf', '--', $root], 30), report: false);
        }

        return $files;
    }
}
