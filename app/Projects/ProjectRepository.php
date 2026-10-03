<?php

namespace App\Projects;

use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Events\ProjectCommitted;
use App\Models\Project;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\Exceptions\RepositoryMissing;
use App\Publishing\GitHubRepositories;
use App\Workspaces\Drivers\CopyExclusions;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The Git repository the builder keeps for a project. Its main branch
 * holds the project as imported plus every change the owner accepted, one
 * commit per change. Each idea the owner tries lives on a branch of its
 * own until it is merged into the main branch or deleted.
 *
 * Methods that take a branch default to the one the owner is working on
 * ({@see Project::branch()}). Commands that write check the branch out
 * inside the repository lock, so the working tree is only ever on the
 * branch being written.
 *
 * Customer code is never run here: Git runs with hooks and signing turned
 * off, and only the builder's own commands touch the working tree, which is
 * kept clean between them.
 */
class ProjectRepository
{
    /**
     * Projects whose repository lock this process holds.
     *
     * @var array<int, true>
     */
    protected static array $holding = [];

    /**
     * When this process last made sure each copy was current, by its path.
     *
     * @var array<string, float>
     */
    protected static array $checkedAt = [];

    public function __construct(private ProjectNotes $notes) {}

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
        // A server that has not seen the project yet gets it from the store.
        if ($this->stored($project) && ! isset(self::$holding[$project->id])) {
            $this->refresh($project);
        }

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
                if ($project->repository_created_at === null) {
                    $project->forceFill(['repository_created_at' => now()])->save();
                }

                return $this->head($project);
            }

            // Made once and gone now: making it again from the source would
            // quietly drop every change the owner kept since.
            if ($project->repository_created_at !== null) {
                throw RepositoryMissing::forProject($project->id);
            }

            if (! File::isDirectory($project->source_path)) {
                throw new RuntimeException(__('The project source :path is not a directory.', ['path' => $project->source_path]));
            }

            $path = $this->path($project);
            File::ensureDirectoryExists($path);

            $copy = Process::run([
                'sh', '-c', 'tar -C "$1" '.CopyExclusions::tarFlags().' -cf - . | tar -C "$2" --no-same-owner -xf -',
                'sh', $project->source_path, $path,
            ]);

            if ($copy->failed()) {
                File::deleteDirectory($path);

                throw new RuntimeException(__('The project source could not be copied: :error', ['error' => trim($copy->errorOutput())]));
            }

            // Notes kept in the app by older versions go to our database,
            // never into the repository (CopyExclusions leaves them out).
            $this->notes->importDirectory($project, config('builder.projects.branch'), $project->source_path.'/'.ProjectContext::LEGACY_DIRECTORY);
            $this->git($project, ['init', '--quiet', '--initial-branch='.config('builder.projects.branch')]);
            $this->git($project, ['add', '--all']);
            $this->commit($project, 'Import '.$project->name, null);
            $project->forceFill(['repository_created_at' => now()])->save();

            return $this->tip($project);
        });
    }

    /**
     * Get the commit at the tip of a branch: by default, the one the owner
     * is working on.
     */
    public function head(Project $project, ?string $branch = null): string
    {
        $branch ??= $project->branch();

        return trim($this->git($project, ['rev-parse', '--verify', '--quiet', "refs/heads/{$branch}"])->output());
    }

    /**
     * Determine whether the branch exists.
     */
    public function hasBranch(Project $project, string $branch): bool
    {
        return $this->git($project, ['rev-parse', '--verify', '--quiet', "refs/heads/{$branch}"], throw: false)->successful();
    }

    /**
     * Get the code changed from one commit to another as a patch, the way a
     * change keeps its code: binary files included, the notes left out.
     */
    public function patch(Project $project, string $from, string $to): string
    {
        return $this->git($project, ['diff', '--binary', '--no-color', '--no-ext-diff', $from, $to, '--', '.', ':(exclude)'.ProjectNotes::directory()])->output();
    }

    /**
     * Start a branch at a commit.
     *
     * @throws RuntimeException when the branch exists or the name is not allowed.
     */
    public function createBranch(Project $project, string $branch, string $from): void
    {
        $this->locked($project, function () use ($project, $branch, $from) {
            $this->git($project, ['check-ref-format', '--branch', $branch]);
            $this->git($project, ['branch', '--no-track', $branch, $from]);
        });
    }

    /**
     * Bring a branch into the main branch as one commit, the way a pull
     * request is squashed: the main branch's history gets one entry for the
     * whole branch, not one for each step taken on it. The message lists
     * those steps under its first line. When the main branch changed the
     * same lines since, nothing is merged. A branch with nothing new merges
     * as the main branch's current commit.
     *
     * The branch's own commits stay readable under refs/kept/, outside
     * every branch, as the changes and edits made on it point at them to
     * show and undo them.
     *
     * @param  array{name: string, email: string}|null  $author
     *
     * @throws RepositoryConflict when the branches changed the same lines.
     */
    public function merge(Project $project, string $branch, string $into, string $message, ?array $author): string
    {
        return $this->locked($project, function () use ($project, $branch, $into, $message, $author) {
            $this->checkout($project, $into);

            if ($this->git($project, ['merge-base', '--is-ancestor', "refs/heads/{$branch}", 'HEAD'], throw: false)->successful()) {
                return $this->tip($project);
            }

            $steps = array_values(array_filter(explode("\n", trim($this->git($project, ['log', '--reverse', '--format=%s', "HEAD..refs/heads/{$branch}"])->output()))));
            $result = $this->git($project, ['merge', '--squash', "refs/heads/{$branch}"], throw: false);

            if ($result->failed() || $this->hasConflicts($project)) {
                $this->discardChanges($project);

                throw new RepositoryConflict(__('Your app changed in the same places since you started this idea.'));
            }

            $this->git($project, ['update-ref', self::kept($branch), "refs/heads/{$branch}"]);

            // Everything on the branch was undone again: the app is as it was.
            if ($this->git($project, ['diff', '--cached', '--quiet'], throw: false)->successful()) {
                return $this->tip($project);
            }

            $this->commit($project, count($steps) > 1 ? $message."\n\n".implode("\n", array_map(fn (string $step) => "* {$step}", $steps)) : $message, $author);

            return $this->tip($project);
        });
    }

    /**
     * Delete a branch and the commits only it holds.
     */
    public function deleteBranch(Project $project, string $branch, string $fallback): void
    {
        $this->locked($project, function () use ($project, $branch, $fallback) {
            $this->checkout($project, $fallback);
            $this->git($project, ['branch', '--delete', '--force', $branch]);
        });
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
            $this->notes->importDirectory($project, config('builder.projects.branch'), $project->source_path.'/'.ProjectContext::LEGACY_DIRECTORY);

            return $callback($project->source_path);
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'builder-checkout-'.Str::random(16);
        File::ensureDirectoryExists($directory);

        try {
            $archive = $directory.'.tar';
            $export = $this->git($project, ['archive', '--format=tar', '--output='.$archive, $revision], throw: false);

            if ($export->successful()) {
                $export = Process::run(['tar', '-C', $directory, '--no-same-owner', '-xf', $archive]);
            }

            File::delete($archive);

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
     * change, only while the branch is still at "base": the patches were
     * checked against that state, and a merge onto newer commits would
     * commit a combination nobody checked.
     *
     * @param  list<string>  $patches
     * @param  array{name: string, email: string}|null  $author
     *
     * @throws RepositoryConflict when the branch has moved on or the patches do not apply.
     */
    public function commitPatches(Project $project, string $base, array $patches, string $message, ?array $author, ?string $branch = null): string
    {
        $branch ??= $project->branch();

        return $this->locked($project, function () use ($project, $base, $patches, $message, $author, $branch) {
            $this->checkout($project, $branch);

            if ($this->tip($project) !== $base) {
                throw new RepositoryConflict(__('The app changed after this change was checked.'));
            }

            foreach ($patches as $patch) {
                $result = Process::path($this->path($project))->input($patch)->run([
                    'git', 'apply', '--index', '--whitespace=nowarn', '-',
                ]);

                if ($result->failed() || $this->hasConflicts($project)) {
                    $this->discardChanges($project);

                    throw new RepositoryConflict(__('The change no longer fits the project.'));
                }
            }

            $this->commit($project, $message, $author);

            return $this->tip($project);
        });
    }

    /**
     * Commit new contents for files, when the branch is still at "base".
     * Callers read the files at "base", so a branch that moved on means they
     * edited an old version.
     *
     * @param  array<string, string|null>  $files  New contents by path; null removes the file
     * @param  array{name: string, email: string}|null  $author
     *
     * @throws RepositoryConflict when the branch has moved on.
     */
    public function commitFiles(Project $project, string $base, array $files, string $message, ?array $author, ?string $branch = null): string
    {
        $branch ??= $project->branch();

        return $this->locked($project, function () use ($project, $base, $files, $message, $author, $branch) {
            $this->checkout($project, $branch);

            if ($this->tip($project) !== $base) {
                throw new RepositoryConflict(__('The app changed while you were editing. Try again on the updated version.'));
            }

            foreach ($files as $path => $contents) {
                if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                    throw new RepositoryConflict(__('The file :path is outside the project.', ['path' => $path]));
                }

                if ($contents === null) {
                    File::isDirectory($this->path($project).'/'.$path)
                        ? File::deleteDirectory($this->path($project).'/'.$path)
                        : File::delete($this->path($project).'/'.$path);

                    continue;
                }

                File::ensureDirectoryExists(dirname($this->path($project).'/'.$path));
                File::put($this->path($project).'/'.$path, $contents);
            }

            $this->commit($project, $message, $author);

            return $this->tip($project);
        });
    }

    /**
     * Get a file's contents at a revision, or null when it does not exist.
     */
    public function show(Project $project, string $revision, string $path): ?string
    {
        $result = $this->git($project, ['show', "{$revision}:{$path}"], throw: false);

        return $result->successful() ? $result->output() : null;
    }

    /**
     * Get which of the given test names appear, as whole words, in the
     * project's test files at a revision.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function testNames(Project $project, string $revision, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $patterns = array_merge(...array_map(fn (string $name) => ['-e', $name], $names));
        $result = $this->git($project, [
            'grep', '-F', '-w', '-o', '-h', ...$patterns, $revision, '--',
            ':(glob)**/tests/**', ':(glob)**/*.test.*', ':(glob)**/*.spec.*',
        ], throw: false);

        return array_values(array_intersect($names, explode("\n", $result->output())));
    }

    /**
     * Get the project's files at a revision.
     *
     * @return list<string>
     */
    public function files(Project $project, string $revision): array
    {
        return array_values(array_filter(explode("\n", trim($this->git($project, ['ls-tree', '-r', '--name-only', $revision])->output()))));
    }

    /**
     * Get the contents of the project's stylesheets at a revision.
     *
     * @return list<string>
     */
    public function stylesheets(Project $project, string $revision): array
    {
        return array_values(array_map(
            fn (string $file) => (string) $this->show($project, $revision, $file),
            array_filter($this->files($project, $revision), fn (string $file) => str_ends_with($file, '.css')),
        ));
    }

    /**
     * Get the files that differ between two revisions, with whether each
     * was deleted.
     *
     * @return array<string, bool> Deleted, by path
     */
    public function changedFiles(Project $project, string $from, string $to): array
    {
        $output = $this->git($project, ['diff', '--name-status', '--no-renames', $from, $to])->output();
        $files = [];

        foreach (array_filter(explode("\n", trim($output))) as $line) {
            [$status, $path] = explode("\t", $line, 2) + ['', ''];
            $files[$path] = $status === 'D';
        }

        return $files;
    }

    /**
     * Undo an accepted commit with a new commit.
     *
     * @param  array{name: string, email: string}|null  $author
     *
     * @throws RepositoryConflict when later commits changed the same lines.
     */
    public function revert(Project $project, string $commit, string $message, ?array $author, ?string $branch = null): string
    {
        $branch ??= $project->branch();

        return $this->locked($project, function () use ($project, $commit, $message, $author, $branch) {
            $this->checkout($project, $branch);
            $result = $this->git($project, ['revert', '--no-commit', $commit], throw: false);

            if ($result->failed() || $this->hasConflicts($project)) {
                $this->discardChanges($project);

                throw new RepositoryConflict(__('The change cannot be undone on its own: later changes build on it.'));
            }

            $this->commit($project, $message, $author);

            return $this->tip($project);
        });
    }

    /**
     * Determine if a commit is in the history of another.
     */
    public function isAncestor(Project $project, string $ancestor, string $commit): bool
    {
        return $this->git($project, ['merge-base', '--is-ancestor', $ancestor, $commit], throw: false)->successful();
    }

    /**
     * Make a commit with the files of "files" on top of "parents", outside
     * any branch, and keep it under "ref". A release uses it to send the
     * files of one commit to a host that has another, without forcing.
     *
     * @param  list<string>  $parents
     * @param  array{name: string, email: string}|null  $author
     */
    public function releaseCommit(Project $project, string $files, array $parents, string $message, ?array $author, string $ref): string
    {
        $author = $this->identity($project, $author);
        $arguments = ['commit-tree', "{$files}^{tree}", '-m', $message];

        foreach ($parents as $parent) {
            array_push($arguments, '-p', $parent);
        }

        // Locked so the new ref is saved to the store with the rest.
        return $this->locked($project, function () use ($project, $arguments, $author, $ref) {
            $commit = trim($this->git($project, $arguments, env: [
                'GIT_AUTHOR_NAME' => $author['name'],
                'GIT_AUTHOR_EMAIL' => $author['email'],
                'GIT_COMMITTER_NAME' => $author['name'],
                'GIT_COMMITTER_EMAIL' => $author['email'],
            ])->output());

            $this->git($project, ['update-ref', $ref, $commit]);

            return $commit;
        });
    }

    /**
     * Push one commit to a branch of another repository, never forcing.
     * Output is returned without the credentials the remote may contain.
     *
     * @throws RepositoryConflict when the branch has commits the project does not.
     * @throws RuntimeException when the push fails for another reason.
     */
    public function push(Project $project, string $commit, string $remote, string $branch): void
    {
        $result = $this->git($project, ['push', '--porcelain', $remote, "{$commit}:refs/heads/{$branch}"], throw: false, timeout: (int) config('builder.publishing.push_timeout'));

        if ($result->successful()) {
            return;
        }

        $output = self::withoutCredentials($result->output()."\n".$result->errorOutput(), $remote);

        if (preg_match('/\[rejected\]|non-fast-forward|fetch first/', $output) === 1) {
            throw new RepositoryConflict(__('The published app has changes that are not in this project, so I did not replace them. Ask your developer to bring them in first.'));
        }

        throw new RuntimeException(trim($output));
    }

    /**
     * Remove credentials from Git output: the remote as given, and any
     * "user:password@" in a URL.
     */
    public static function withoutCredentials(string $output, string $remote): string
    {
        $safeRemote = (string) preg_replace('#(://)[^/@\s]+@#', '$1', $remote);

        return (string) preg_replace('#(://)[^/@\s]+@#', '$1', str_replace($remote, $safeRemote, $output));
    }

    /**
     * Get a branch's commits, newest first.
     *
     * @return list<array{sha: string, subject: string, author: string, committed_at: string}>
     */
    public function log(Project $project, int $limit = 50, ?string $branch = null): array
    {
        if (! $this->exists($project)) {
            return [];
        }

        $branch ??= $project->branch();
        $output = $this->git($project, ['log', "--max-count={$limit}", '--format=%H%x1f%s%x1f%an%x1f%cI', "refs/heads/{$branch}"])->output();

        return array_values(array_map(function (string $line) {
            [$sha, $subject, $author, $committedAt] = explode("\x1f", $line) + ['', '', '', ''];

            return ['sha' => $sha, 'subject' => $subject, 'author' => $author, 'committed_at' => $committedAt];
        }, array_filter(explode("\n", trim($output)))));
    }

    /**
     * Get where a merged branch's own commits are kept once the branch is
     * gone (see merge()).
     */
    public static function kept(string $branch): string
    {
        return "refs/kept/{$branch}";
    }

    /**
     * Get every commit a commit is built on, itself included.
     *
     * @return list<string>
     */
    public function history(Project $project, string $commit): array
    {
        return array_values(array_filter(explode("\n", trim($this->git($project, ['rev-list', $commit])->output()))));
    }

    /**
     * Run a Git command in the project's repository. The repository belongs
     * to the control plane, so it is trusted even when another system user
     * (a queue worker, for example) created it; hooks never run.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env  More environment, such as a separate index
     *
     * @throws RuntimeException when the command fails and "throw" is set.
     */
    public function git(Project $project, array $arguments, bool $throw = true, int $timeout = 60, array $env = []): ProcessResult
    {
        if ($this->stored($project) && ! isset(self::$holding[$project->id])) {
            $this->refresh($project);
        }

        if ($project->repository_created_at !== null && ! File::isDirectory($this->path($project))) {
            throw RepositoryMissing::forProject($project->id);
        }

        $result = Process::path($this->path($project))
            ->timeout($timeout)
            ->env([
                'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_TERMINAL_PROMPT' => '0',
                ...$this->committer(),
                ...$env,
            ])
            ->run(['git', '-c', 'safe.directory='.$this->path($project), '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments]);

        if ($throw && $result->failed()) {
            throw new RuntimeException(sprintf('git %s failed: %s', $arguments[0], trim($result->errorOutput())));
        }

        return $result;
    }

    /**
     * The committer an operator set, if any. Without one, each commit is
     * committed by its author, so the history names no tool.
     *
     * @return array<string, string>
     */
    protected function committer(): array
    {
        $committer = config('builder.projects.committer');

        return filled($committer['name'] ?? null) && filled($committer['email'] ?? null)
            ? ['GIT_COMMITTER_NAME' => $committer['name'], 'GIT_COMMITTER_EMAIL' => $committer['email']]
            : [];
    }

    /**
     * Who writes a commit: its author when known, else the operator's
     * committer, else the project's owner.
     *
     * @param  array{name: string, email: string}|null  $author
     * @return array{name: string, email: string}
     */
    protected function identity(Project $project, ?array $author): array
    {
        $committer = $this->committer();

        return $author ?? ($committer !== []
            ? ['name' => $committer['GIT_COMMITTER_NAME'], 'email' => $committer['GIT_COMMITTER_EMAIL']]
            : ['name' => $project->owner->name, 'email' => $project->owner->email]);
    }

    /**
     * Commit what is staged and announce the new commit.
     *
     * @param  array{name: string, email: string}|null  $author
     */
    protected function commit(Project $project, string $message, ?array $author): void
    {
        $author = $this->identity($project, $author);

        $this->git($project, ['add', '--all']);
        $this->git($project, [
            '-c', "user.name={$author['name']}", '-c', "user.email={$author['email']}",
            'commit', '--quiet', '--allow-empty', '--no-verify', '--author', "{$author['name']} <{$author['email']}>", '-m', $message,
        ]);

        ProjectCommitted::dispatch($project, $this->tip($project));
    }

    /**
     * Get the commit the working tree is on.
     */
    protected function tip(Project $project): string
    {
        return trim($this->git($project, ['rev-parse', 'HEAD'])->output());
    }

    /**
     * Put the working tree on a branch. Only called inside the lock, where
     * the tree is clean.
     */
    protected function checkout(Project $project, string $branch): void
    {
        $this->git($project, ['checkout', '--quiet', '--force', $branch, '--']);
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

        return $lock->block(60, function () use ($project, $callback) {
            self::$holding[$project->id] = true;

            try {
                if (! $this->stored($project)) {
                    return $callback();
                }

                // Another server may have saved newer commits. Writing on an
                // older copy would drop them when this one is saved.
                $this->fetch($project);
                $before = $this->refs($project);
                $result = $callback();

                if ($this->refs($project) !== $before) {
                    $this->save($project);
                }

                return $result;
            } finally {
                unset(self::$holding[$project->id]);
            }
        });
    }

    /**
     * Determine whether the project's repository is kept in the project
     * store, off this server's disk.
     */
    protected function stored(Project $project): bool
    {
        return in_array(config('builder.projects.store.driver'), ['github', 'disk'], true);
    }

    protected function storedOnGitHub(): bool
    {
        return config('builder.projects.store.driver') === 'github';
    }

    /**
     * Make sure this server's copy has every commit saved to the store, at
     * most once a second, so reads on any server see the newest change.
     */
    protected function refresh(Project $project): void
    {
        $checked = self::$checkedAt[$this->path($project)] ?? 0.0;

        if (microtime(true) - $checked < 1.0) {
            return;
        }

        if ($this->localVersion($project) !== $this->storedVersion($project)) {
            $this->locked($project, fn () => null);
        }

        self::$checkedAt[$this->path($project)] = microtime(true);
    }

    /**
     * Bring this server's copy up to the store's, or make it from the store
     * when this server has none. Only called inside the lock.
     */
    protected function fetch(Project $project): void
    {
        $version = $this->storedVersion($project);

        if ($version === 0 || $this->localVersion($project) === $version) {
            return;
        }

        if ($this->storedOnGitHub()) {
            $this->fetchFromGitHub($project, $version);

            return;
        }

        $disk = Storage::disk((string) config('builder.projects.store.disk'));
        $object = $this->storeObject($project);

        if (! $disk->exists($object)) {
            throw RepositoryMissing::forProject($project->id);
        }

        $bundle = (string) tempnam(sys_get_temp_dir(), 'project-bundle-');

        try {
            File::put($bundle, '');
            $stream = $disk->readStream($object);
            $target = fopen($bundle, 'w');

            if ($stream === null || $target === false) {
                throw new RuntimeException("The project store could not read {$object}.");
            }

            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);

            $this->initialize($project);
            $this->git($project, ['fetch', '--quiet', '--prune', '--update-head-ok', $bundle, '+refs/*:refs/*'], timeout: 300);
            $this->discardChanges($project);
            $this->writeLocalVersion($project, $version);
        } finally {
            File::delete($bundle);
        }
    }

    /**
     * Fetch every ref from the project's store repository on GitHub.
     */
    protected function fetchFromGitHub(Project $project, int $version): void
    {
        $this->initialize($project);
        $remote = $this->storeRemote($project);
        $result = $this->git($project, ['fetch', '--quiet', '--prune', '--update-head-ok', $remote, '+refs/*:refs/*'], throw: false, timeout: 300);

        if ($result->failed()) {
            $output = self::withoutCredentials($result->errorOutput(), $remote);

            // The saved copy is gone, not just out of reach.
            if (preg_match('/not found|does not appear to be a git repository/i', $output) === 1) {
                throw RepositoryMissing::forProject($project->id);
            }

            throw new RuntimeException(trim($output));
        }

        $this->discardChanges($project);
        $this->writeLocalVersion($project, $version);
    }

    /**
     * Make an empty repository for a copy this server does not have yet.
     */
    protected function initialize(Project $project): void
    {
        if (! File::isDirectory($this->path($project).DIRECTORY_SEPARATOR.'.git')) {
            File::ensureDirectoryExists($this->path($project));
            $this->git($project, ['init', '--quiet', '--initial-branch='.config('builder.projects.branch')]);
        }
    }

    /**
     * Save the whole repository to the store and count the save. When the
     * save fails, this copy is marked as out of date, so the next use takes
     * the store's copy and the unsaved commits are not kept anywhere.
     */
    protected function save(Project $project): void
    {
        $bundle = (string) tempnam(sys_get_temp_dir(), 'project-bundle-');

        try {
            File::delete($bundle);

            if ($this->storedOnGitHub()) {
                $this->pushToGitHub($project);
            } else {
                $this->git($project, ['bundle', 'create', '--quiet', $bundle, '--all'], timeout: 300);
                $this->writeBundle($project, $bundle);
            }

            Project::query()->whereKey($project->id)->increment('repository_version');
            $this->writeLocalVersion($project, $this->storedVersion($project));
        } catch (Throwable $exception) {
            $this->writeLocalVersion($project, -1);

            throw new RuntimeException(__('This is our fault: we could not save your app\'s code, so the change was not kept. Please try again.'), previous: $exception);
        } finally {
            File::delete($bundle);
        }
    }

    protected function writeBundle(Project $project, string $bundle): void
    {
        $stream = fopen($bundle, 'r');

        try {
            if ($stream === false || ! Storage::disk((string) config('builder.projects.store.disk'))->writeStream($this->storeObject($project), $stream)) {
                throw new RuntimeException('The project store did not take the repository.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Push every ref to the project's store repository on GitHub, making the
     * repository on the first save. A mirror push also removes refs that
     * were removed here; the lock and the fetch before each change make
     * this copy the newest one.
     */
    protected function pushToGitHub(Project $project): void
    {
        if ($this->storedVersion($project) === 0) {
            app(GitHubRepositories::class)->ensureNamed($this->storeOrganization(), $this->storeName($project));
        }

        $remote = $this->storeRemote($project);
        $result = $this->git($project, ['push', '--quiet', '--mirror', $remote], throw: false, timeout: (int) config('builder.publishing.push_timeout'));

        if ($result->failed()) {
            throw new RuntimeException(trim(self::withoutCredentials($result->errorOutput(), $remote)));
        }
    }

    /**
     * Get every ref and the commit it points at, to tell whether a command
     * changed the repository.
     */
    protected function refs(Project $project): string
    {
        if (! File::isDirectory($this->path($project).DIRECTORY_SEPARATOR.'.git')) {
            return '';
        }

        return $this->git($project, ['for-each-ref', '--format=%(refname) %(objectname)'])->output();
    }

    /**
     * Name the project's store repository by its ID alone, so a renamed app
     * keeps it. The prefix keeps builders that share an organization apart.
     */
    protected function storeName(Project $project): string
    {
        return config('builder.projects.store.prefix')."-{$project->id}";
    }

    protected function storeOrganization(): string
    {
        return (string) (config('builder.projects.store.organization') ?: config('builder.publishing.github.organization'));
    }

    protected function storeRemote(Project $project): string
    {
        return app(GitHubRepositories::class)->remote($this->storeOrganization().'/'.$this->storeName($project));
    }

    protected function storeObject(Project $project): string
    {
        return trim((string) config('builder.projects.store.prefix'), '/')."/{$project->id}.bundle";
    }

    protected function storedVersion(Project $project): int
    {
        return (int) Project::query()->whereKey($project->id)->value('repository_version');
    }

    /**
     * Get the store version this server's copy matches: 0 without a copy,
     * -1 when its last save failed.
     */
    protected function localVersion(Project $project): int
    {
        $file = $this->path($project).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'builder-store-version';

        return File::exists($file) ? (int) File::get($file) : 0;
    }

    protected function writeLocalVersion(Project $project, int $version): void
    {
        File::put($this->path($project).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'builder-store-version', (string) $version);
    }
}
