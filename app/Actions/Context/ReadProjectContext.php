<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\ProjectContext;
use App\Models\Project;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\WorkspaceManager;
use Closure;

class ReadProjectContext
{
    public function __construct(private WorkspaceManager $workspaces, private ProjectRepository $repository) {}

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

        return $this->read($files, fn (string $path) => $driver->readFile((string) $workspace->driver_id, $path));
    }

    /**
     * Read the notes as they are in the project's repository at a revision.
     */
    public function atRevision(Project $project, string $revision): ProjectContext
    {
        return $this->read(
            $this->repository->files($project, $revision),
            fn (string $path) => $this->repository->show($project, $revision, $path),
        );
    }

    /**
     * Read the notes through a function that returns a file's contents.
     *
     * @param  list<string>  $files  The project's files
     * @param  Closure(string): (string|null)  $contents
     */
    protected function read(array $files, Closure $contents): ProjectContext
    {
        $limit = (int) config('builder.context.max_file_bytes');
        $problems = [];

        $read = function (string $path) use ($contents, $limit, &$problems): ?string {
            $text = rescue(fn () => $contents($path), null, report: false);

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
                $capability = Capability::fromMarkdown($path, $text)->withTestFilesFrom($files);
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
