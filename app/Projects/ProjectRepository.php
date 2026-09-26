<?php

namespace App\Projects;

use App\Models\Project;
use App\Projects\Exceptions\RepositoryConflict;
use App\Workspaces\Drivers\CopyExclusions;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Git repository the builder keeps for a project. Its default branch
 * holds the project as imported plus every change the owner accepted, one
 * commit per change.
 *
 * Customer code is never run here: Git runs with hooks and signing turned
 * off, and only the builder's own commands touch the working tree, which is
 * kept clean between them.
 */
class ProjectRepository
{
    /**
     * Get the directory of the project's repository.
     */
    public function path(Project $project): string
    {
        return rtrim((string) config('builder.projects.root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$project->id;
    }

    /**
     * Determine whether the project's repository has been created.
     */
    public function exists(Project $project): bool
    {
        return File::isDirectory($this->path($project).DIRECTORY_SEPARATOR.'.git');
    }

    /**
     * Create the project's repository from its source directory, as one
     * "Import" commit, unless it exists already. Installed dependencies,
     * build output, secrets and the source's own Git data are left out.
     *
     * @throws RuntimeException when the source cannot be read.
     */
    public function import(Project $project): string
    {
        return $this->locked($project, function () use ($project) {
            if ($this->exists($project)) {
                return $this->head($project);
            }

            if (! File::isDirectory($project->source_path)) {
                throw new RuntimeException(__('The project source :path is not a directory.', ['path' => $project->source_path]));
            }

            $path = $this->path($project);
            File::ensureDirectoryExists($path);

            $copy = Process::run([
                'sh', '-c', 'tar -C "$1" '.CopyExclusions::tarFlags().' -cf - . | tar -C "$2" -xf -',
                'sh', $project->source_path, $path,
            ]);

            if ($copy->failed()) {
                File::deleteDirectory($path);

                throw new RuntimeException(__('The project source could not be copied: :error', ['error' => trim($copy->errorOutput())]));
            }

            $this->git($project, ['init', '--quiet', '--initial-branch='.config('builder.projects.branch')]);
            $this->git($project, ['add', '--all']);
            $this->commit($project, 'Import '.$project->name, null);

            return $this->head($project);
        });
    }

    /**
     * Get the commit at the tip of the project's branch.
     */
    public function head(Project $project): string
    {
        return trim($this->git($project, ['rev-parse', 'HEAD'])->output());
    }

    /**
     * Get the project's first commit: the source as it was imported.
     */
    public function root(Project $project): string
    {
        return trim(Str::before($this->git($project, ['rev-list', '--max-parents=0', 'HEAD'])->output(), "\n"));
    }

    /**
     * Call the callback with a directory holding the project as it was at
     * the revision, then remove the directory. Without a revision, or for a
     * project with no repository yet, the callback gets the source directory.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function withCheckout(Project $project, ?string $revision, Closure $callback): mixed
    {
        if ($revision === null || ! $this->exists($project)) {
            return $callback($project->source_path);
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'builder-checkout-'.Str::random(16);
        File::ensureDirectoryExists($directory);

        try {
            $export = Process::path($this->path($project))->run([
                'sh', '-c', 'git archive --format=tar "$1" | tar -C "$2" -xf -', 'sh', $revision, $directory,
            ]);

            if ($export->failed()) {
                throw new RuntimeException(__('Revision :revision could not be checked out: :error', ['revision' => $revision, 'error' => trim($export->errorOutput())]));
            }

            return $callback($directory);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Apply the patches in order on top of the branch and commit them as one
     * change. When the branch has moved on since "base", Git's three-way
     * merge is used; if the patches no longer fit, nothing is committed.
     *
     * @param  list<string>  $patches
     * @param  array{name: string, email: string}|null  $author
     *
     * @throws RepositoryConflict when the patches do not apply.
     */
    public function commitPatches(Project $project, string $base, array $patches, string $message, ?array $author): string
    {
        return $this->locked($project, function () use ($project, $base, $patches, $message, $author) {
            $threeWay = $this->head($project) !== $base;

            foreach ($patches as $patch) {
                $result = Process::path($this->path($project))->input($patch)->run([
                    'git', 'apply', '--index', '--whitespace=nowarn', ...($threeWay ? ['--3way'] : []), '-',
                ]);

                if ($result->failed() || $this->hasConflicts($project)) {
                    $this->discardChanges($project);

                    throw new RepositoryConflict(__('The change no longer fits the project, which has changed since it was built.'));
                }
            }

            $this->commit($project, $message, $author);

            return $this->head($project);
        });
    }

    /**
     * Undo an accepted commit with a new commit.
     *
     * @param  array{name: string, email: string}|null  $author
     *
     * @throws RepositoryConflict when later commits changed the same lines.
     */
    public function revert(Project $project, string $commit, string $message, ?array $author): string
    {
        return $this->locked($project, function () use ($project, $commit, $message, $author) {
            $result = $this->git($project, ['revert', '--no-commit', $commit], throw: false);

            if ($result->failed() || $this->hasConflicts($project)) {
                $this->discardChanges($project);

                throw new RepositoryConflict(__('The change cannot be undone on its own: later changes build on it.'));
            }

            $this->commit($project, $message, $author);

            return $this->head($project);
        });
    }

    /**
     * Get the project's commits, newest first.
     *
     * @return list<array{sha: string, subject: string, author: string, committed_at: string}>
     */
    public function log(Project $project, int $limit = 50): array
    {
        if (! $this->exists($project)) {
            return [];
        }

        $output = $this->git($project, ['log', "--max-count={$limit}", '--format=%H%x1f%s%x1f%an%x1f%cI'])->output();

        return array_values(array_map(function (string $line) {
            [$sha, $subject, $author, $committedAt] = explode("\x1f", $line) + ['', '', '', ''];

            return ['sha' => $sha, 'subject' => $subject, 'author' => $author, 'committed_at' => $committedAt];
        }, array_filter(explode("\n", trim($output)))));
    }

    /**
     * Run a Git command in the project's repository.
     *
     * @param  list<string>  $arguments
     *
     * @throws RuntimeException when the command fails and "throw" is set.
     */
    public function git(Project $project, array $arguments, bool $throw = true): ProcessResult
    {
        $committer = config('builder.projects.committer');

        $result = Process::path($this->path($project))
            ->env([
                'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_COMMITTER_NAME' => $committer['name'],
                'GIT_COMMITTER_EMAIL' => $committer['email'],
            ])
            ->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments]);

        if ($throw && $result->failed()) {
            throw new RuntimeException(sprintf('git %s failed: %s', $arguments[0], trim($result->errorOutput())));
        }

        return $result;
    }

    /**
     * Commit what is staged, authored by the owner when known.
     *
     * @param  array{name: string, email: string}|null  $author
     */
    protected function commit(Project $project, string $message, ?array $author): void
    {
        $author ??= config('builder.projects.committer');

        $this->git($project, ['add', '--all']);
        $this->git($project, [
            '-c', "user.name={$author['name']}", '-c', "user.email={$author['email']}",
            'commit', '--quiet', '--allow-empty', '--no-verify', '--author', "{$author['name']} <{$author['email']}>", '-m', $message,
        ]);
    }

    /**
     * Determine whether the working tree has unmerged paths.
     */
    protected function hasConflicts(Project $project): bool
    {
        return trim($this->git($project, ['diff', '--name-only', '--diff-filter=U'])->output()) !== '';
    }

    /**
     * Put the working tree back to the branch's last commit.
     */
    protected function discardChanges(Project $project): void
    {
        $this->git($project, ['reset', '--quiet', '--hard', 'HEAD']);
        $this->git($project, ['clean', '--quiet', '-fd']);
    }

    /**
     * Run the callback while no other process changes the repository.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function locked(Project $project, Closure $callback): mixed
    {
        /** @var Lock $lock */
        $lock = Cache::lock("project-repository:{$project->id}", 120);

        return $lock->block(60, $callback);
    }
}
