<?php

namespace App\Workspaces;

use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Puts what a workspace needs besides the code into it, from our
 * database: the files its setup made the first time (such as `.env`) and
 * the project's notes. The database is the lasting copy; a workspace is
 * only a working copy.
 */
class WorkspaceFiles
{
    /**
     * Where the pictures an owner attached are put in a workspace: inside
     * git's own folder, so they are never part of the app or its change.
     */
    public const IMAGES_DIRECTORY = '.git/attachments';

    public function __construct(private WorkspaceManager $workspaces, private ProjectNotes $notes) {}

    /**
     * After setup: give the workspace the saved files, and save the ones
     * this workspace made that were not saved yet.
     */
    public function sync(Project $project, Workspace $workspace): void
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $saved = $project->workspaceFiles()->pluck('contents', 'path')->all();

        foreach (Config::array('builder.projects.workspace_files') as $path) {
            if (! is_string($path)) {
                continue;
            }

            if (isset($saved[$path])) {
                $contents = $saved[$path];

                if ($path === '.env') {
                    $current = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);
                    $contents = self::keepOwnServer($contents, is_string($current) ? $current : '');
                }

                $driver->writeFile((string) $workspace->driver_id, $path, $contents);

                continue;
            }

            $contents = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);

            if (is_string($contents)) {
                $project->workspaceFiles()->firstOrCreate(['path' => $path], ['contents' => $contents]);
            }
        }
    }

    /**
     * Keep this workspace's own database server in a saved .env. Setup
     * starts a database server for each workspace and writes where it
     * listens (a socket in the workspace's temp folder) into .env; the
     * saved .env still names the server of the workspace it was saved
     * from, which is gone. So a value that is a path comes from this
     * workspace's .env, and is left out when this workspace has none.
     */
    public static function keepOwnServer(string $saved, string $current): string
    {
        foreach (['DB_SOCKET', 'DB_HOST'] as $name) {
            $pattern = '/^'.$name.'=.*$/m';
            $withEnd = '/^'.$name.'=.*\n?/m';
            $own = preg_match($pattern, $current, $match) === 1 ? $match[0] : null;
            $old = preg_match($pattern, $saved, $match) === 1 ? $match[0] : null;
            $isPath = fn (?string $line) => $line !== null && str_starts_with(trim(substr($line, strlen($name) + 1), "\"' "), '/');

            if (! $isPath($own) && ! $isPath($old)) {
                continue;
            }

            $saved = (string) preg_replace($withEnd, '', $saved);
            $saved = $own === null ? $saved : rtrim($saved, "\n")."\n{$own}\n";
        }

        return $saved;
    }

    /**
     * Write the notes a change starts from into the workspace: the notes of
     * its line of work, as the changes it follows up on left them.
     */
    public function placeNotes(FeatureRequest $featureRequest, Workspace $workspace): void
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $files = $this->notes->files($featureRequest->project, $featureRequest->branch() ?? $featureRequest->project->branch());

        foreach (array_slice($featureRequest->lineage(), 0, -1) as $ancestor) {
            foreach ($ancestor->note_changes ?? [] as $path => $change) {
                $files[$path] = $change['after'];
            }
        }

        foreach (array_filter($files, is_string(...)) as $path => $contents) {
            ProjectNotes::assertPath($path);
            $driver->writeFile((string) $workspace->driver_id, ProjectNotes::directory().'/'.$path, $contents);
        }
    }

    /**
     * Put the pictures the owner attached to a change into the workspace,
     * where the coder can look at them.
     */
    public function placeImages(FeatureRequest $featureRequest, Workspace $workspace): void
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $disk = Storage::disk(Config::string('builder.construction.images.disk'));

        foreach (self::imagePaths($featureRequest) as $index => $path) {
            $driver->writeFile((string) $workspace->driver_id, $path, (string) $disk->get($featureRequest->images[$index]['path']));
        }
    }

    /**
     * Get where each picture the owner attached is in the workspace.
     *
     * @return list<string>
     */
    public static function imagePaths(FeatureRequest $featureRequest): array
    {
        return array_map(
            fn (array $image, int $index) => self::IMAGES_DIRECTORY.'/'.($index + 1).'.'.pathinfo($image['path'], PATHINFO_EXTENSION),
            $featureRequest->images ?? [],
            array_keys($featureRequest->images ?? []),
        );
    }
}
