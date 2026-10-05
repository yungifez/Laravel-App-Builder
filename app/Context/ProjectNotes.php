<?php

namespace App\Context;

use App\Models\Project;
use App\Models\ProjectNote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * What we know about a project's product, kept in our database for each
 * line of work (the main branch and each idea's branch). A write is saved
 * here first and is available at once; workspaces get a copy when they are
 * made and send their changes back through accepted runs.
 */
class ProjectNotes
{
    /**
     * Where a workspace holds its copy of the notes. The name says nothing
     * about who put them there.
     */
    public static function directory(): string
    {
        return trim((string) config('builder.context.directory'), '/');
    }

    /**
     * Get the notes of a line of work by path, in path order.
     *
     * @return array<string, string>
     */
    public function files(Project $project, ?string $branch = null): array
    {
        return $project->notes()
            ->where('branch', $branch ?? $project->branch())
            ->orderBy('path')
            ->pluck('contents', 'path')
            ->all();
    }

    /**
     * Get a fingerprint of the notes, so an edit made on an old copy can
     * be refused.
     */
    public function version(Project $project, ?string $branch = null): string
    {
        return sha1((string) json_encode($this->files($project, $branch)));
    }

    /**
     * Save files, removing those set to null.
     *
     * @param  array<string, string|null>  $files
     */
    public function put(Project $project, string $branch, array $files): void
    {
        DB::transaction(function () use ($project, $branch, $files) {
            foreach ($files as $path => $contents) {
                self::assertPath($path);

                $before = $project->notes()->where('branch', $branch)->where('path', $path)->value('contents');

                if ($before === $contents) {
                    continue;
                }

                // Each write is kept, so the notes have a history like the
                // code does, and who wrote it.
                $project->noteRevisions()->create(['branch' => $branch, 'path' => $path, 'contents' => $contents, 'user_id' => Auth::id()]);

                if ($contents === null) {
                    $project->notes()->where('branch', $branch)->where('path', $path)->delete();

                    continue;
                }

                $project->notes()->updateOrCreate(['branch' => $branch, 'path' => $path], ['contents' => $contents]);
            }
        });
    }

    /**
     * Apply what a change did to the notes. A file someone else changed
     * since the change read it keeps their version.
     *
     * @param  array<string, array{before: string|null, after: string|null}>  $changes
     * @return list<string> The paths left as they were
     */
    public function apply(Project $project, string $branch, array $changes): array
    {
        return $this->swap($project, $branch, $changes, 'before', 'after');
    }

    /**
     * Undo what a change did to the notes, where nobody changed them since.
     *
     * @param  array<string, array{before: string|null, after: string|null}>  $changes
     * @return list<string> The paths left as they were
     */
    public function undo(Project $project, string $branch, array $changes): array
    {
        return $this->swap($project, $branch, $changes, 'after', 'before');
    }

    /**
     * Start an idea's notes as a copy of the main app's.
     */
    public function copy(Project $project, string $from, string $to): void
    {
        DB::transaction(function () use ($project, $from, $to) {
            foreach ($this->files($project, $from) as $path => $contents) {
                $project->notes()->updateOrCreate(['branch' => $to, 'path' => $path], ['contents' => $contents, 'base_hash' => sha1($contents)]);
            }
        });
    }

    /**
     * Bring what an idea changed in its notes into the main app, then
     * forget the idea's copy.
     */
    public function merge(Project $project, string $from, string $into): void
    {
        DB::transaction(function () use ($project, $from, $into) {
            $changed = $project->notes()->where('branch', $from)->get()
                ->filter(fn (ProjectNote $note) => $note->base_hash !== sha1($note->contents));

            $this->put($project, $into, $changed->pluck('contents', 'path')->all());
            $this->forget($project, $from);
        });
    }

    /**
     * Bring in notes kept in a directory, when the line of work has none yet.
     */
    public function importDirectory(Project $project, string $branch, string $directory): void
    {
        if (! File::isDirectory($directory) || $this->files($project, $branch) !== []) {
            return;
        }

        $files = [];

        foreach (File::allFiles($directory, hidden: true) as $file) {
            $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
        }

        $this->put($project, $branch, $files);
    }

    /**
     * Forget a line of work's notes.
     */
    public function forget(Project $project, string $branch): void
    {
        $project->notes()->where('branch', $branch)->delete();
        $project->noteRevisions()->where('branch', $branch)->delete();
    }

    /**
     * Set each file from one side of its change to the other, when it is
     * still as that side left it.
     *
     * @param  array<string, array{before: string|null, after: string|null}>  $changes
     * @return list<string>
     */
    protected function swap(Project $project, string $branch, array $changes, string $from, string $to): array
    {
        return DB::transaction(function () use ($project, $branch, $changes, $from, $to) {
            $current = $project->notes()->where('branch', $branch)->lockForUpdate()->pluck('contents', 'path')->all();
            $skipped = [];
            $writes = [];

            foreach ($changes as $path => $change) {
                if (($current[$path] ?? null) !== $change[$from]) {
                    $skipped[] = $path;

                    continue;
                }

                $writes[$path] = $change[$to];
            }

            $this->put($project, $branch, $writes);

            return $skipped;
        });
    }

    /**
     * Refuse a path that could reach outside the notes directory.
     *
     * @throws InvalidArgumentException
     */
    public static function assertPath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException("The notes path [{$path}] is not allowed.");
        }
    }
}
