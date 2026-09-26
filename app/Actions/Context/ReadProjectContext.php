<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\WorkspaceManager;
use Closure;

class ReadProjectContext
{
    public function __construct(
        private WorkspaceManager $workspaces,
        private ProjectRepository $repository,
        private ProjectNotes $notes,
    ) {}

    /**
     * Read a workspace's copy of the notes, with any changes made in it. A
     * file that cannot be read is reported as a problem and left out; it
     * never stops a run.
     *
     * @param  list<string>  $files  The workspace's files
     */
    public function handle(Workspace $workspace, array $files): ProjectContext
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $prefix = ProjectNotes::directory().'/';
        $notes = array_values(array_map(
            fn (string $path) => substr($path, strlen($prefix)),
            array_filter($files, fn (string $path) => str_starts_with($path, $prefix)),
        ));

        return $this->read($files, $notes, fn (string $path) => $driver->readFile((string) $workspace->driver_id, $prefix.$path));
    }

    /**
     * Read the notes of a line of work as they are now, against the code at
     * the tip of its branch.
     */
    public function current(Project $project, ?string $branch = null): ProjectContext
    {
        $branch ??= $project->branch();
        $notes = $this->notes->files($project, $branch);
        $files = $this->repository->exists($project) ? $this->repository->files($project, $this->repository->head($project, $branch)) : [];

        return $this->read($files, array_keys($notes), fn (string $path) => $notes[$path] ?? null);
    }

    /**
     * Read the notes through a function that returns a file's contents.
     *
     * @param  list<string>  $files  The project's code files
     * @param  list<string>  $notes  The notes' paths
     * @param  Closure(string): (string|null)  $contents
     */
    protected function read(array $files, array $notes, Closure $contents): ProjectContext
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

        $project = in_array(ProjectContext::PROJECT_FILE, $notes, true) ? $read(ProjectContext::PROJECT_FILE) : null;
        $capabilities = [];

        foreach ($notes as $path) {
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
