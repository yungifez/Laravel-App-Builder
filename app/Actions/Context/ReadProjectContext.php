<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\ProjectContext;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;

class ReadProjectContext
{
    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Read the application's `.builder/` notes from a workspace. A file that
     * cannot be read is reported as a problem and left out; it never stops a
     * run.
     *
     * @param  list<string>  $files  The workspace's files
     */
    public function handle(Workspace $workspace, array $files): ProjectContext
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $limit = (int) config('builder.context.max_file_bytes');
        $problems = [];

        $read = function (string $path) use ($driver, $workspace, $limit, &$problems): ?string {
            $text = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);

            if (! is_string($text)) {
                $problems[] = __(':path: the file could not be read.', ['path' => $path]);

                return null;
            }

            if (strlen($text) > $limit) {
                $problems[] = __(':path: the file is larger than :limit bytes.', ['path' => $path, 'limit' => $limit]);

                return null;
            }

            return $text;
        };

        $project = in_array(ProjectContext::PROJECT_FILE, $files, true) ? $read(ProjectContext::PROJECT_FILE) : null;
        $capabilities = [];

        foreach ($files as $path) {
            if (! str_starts_with($path, ProjectContext::CAPABILITIES_DIRECTORY.'/') || ! str_ends_with($path, '.md')) {
                continue;
            }

            $text = $read($path);

            if ($text === null) {
                continue;
            }

            try {
                $capability = Capability::fromMarkdown($path, $text);
            } catch (InvalidContextFile $exception) {
                $problems[] = $exception->getMessage();

                continue;
            }

            $existing = $capabilities[$capability->key] ?? null;

            if ($existing !== null) {
                // The file named after the area wins over any other claiming it.
                [$kept, $dropped] = pathinfo($path, PATHINFO_FILENAME) === $capability->key ? [$capability, $existing] : [$existing, $capability];
                $problems[] = __(':path: another file already describes ":key".', ['path' => $dropped->file, 'key' => $capability->key]);
                $capabilities[$capability->key] = $kept;

                continue;
            }

            $capabilities[$capability->key] = $capability;
        }

        ksort($capabilities);

        return new ProjectContext($project === null ? null : trim($project), $capabilities, $problems);
    }
}
