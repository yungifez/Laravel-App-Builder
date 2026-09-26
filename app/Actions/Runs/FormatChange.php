<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Context\ProjectNotes;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;

class FormatChange
{
    public function __construct(private RunWorkspaceCommand $runWorkspaceCommand) {}

    /**
     * Run the project's formatters on the files the change touched, so a
     * long line never costs a repair. Only changed files are formatted: the
     * rest of the app stays exactly as its developers left it.
     *
     * @return list<string> The formatters that ran
     */
    public function handle(Workspace $workspace): array
    {
        $files = $this->changedFiles($workspace);
        $ran = [];

        foreach (Config::array('builder.construction.formatters') as $formatter) {
            if (! is_array($formatter) || ! is_array($formatter['command'] ?? null)) {
                continue;
            }

            $extensions = (array) ($formatter['extensions'] ?? []);
            $matching = array_values(array_filter($files, fn (string $file) => in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true)));

            if ($matching === []) {
                continue;
            }

            $result = rescue(fn () => $this->runWorkspaceCommand->handle(
                $workspace,
                [...array_values(array_map(strval(...), $formatter['command'])), ...$matching],
                (int) ($formatter['timeout'] ?? 120),
            ), null, report: false);

            if ($result !== null && $result->exit_code === 0 && ! $result->timed_out) {
                $ran[] = (string) ($formatter['name'] ?? $formatter['command'][0]);
            }
        }

        return $ran;
    }

    /**
     * List the files the change added or modified, leaving out the notes.
     *
     * @return list<string>
     */
    protected function changedFiles(Workspace $workspace): array
    {
        $this->runWorkspaceCommand->handle($workspace, ['git', 'add', '--all'], 120);
        $output = $this->runWorkspaceCommand->handle($workspace, ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z', '--', '.', ':(exclude)'.ProjectNotes::directory()], 120)->output;

        return array_values(array_filter(explode("\0", $output)));
    }
}
