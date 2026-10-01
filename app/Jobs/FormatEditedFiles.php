<?php

namespace App\Jobs;

use App\Actions\Workspaces\FormatAppFiles;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\FormattedRevisions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

class FormatEditedFiles implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: two formatters.
     */
    public int $timeout = 300;

    /**
     * Formatting is not retried; the next change formats the files again.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Preview $preview, public string $revision)
    {
        $this->onQueue(config('builder.preview.queue'));
    }

    /**
     * Remember files a rebuild brought into the preview, to format them once
     * the owner pauses, and ask for that.
     *
     * @param  list<string>  $paths
     */
    public static function after(Preview $preview, string $revision, array $paths): void
    {
        if (! config('builder.preview.format.enabled') || $paths === []) {
            return;
        }

        Cache::lock(self::key($preview).':lock', 10)->block(5, function () use ($preview, $paths) {
            Cache::put(self::key($preview), array_values(array_unique([...self::paths($preview), ...$paths])), now()->addDay());
        });

        self::dispatch($preview, $revision)->delay(now()->addSeconds((int) config('builder.preview.format.after_seconds')));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("preview-format:{$this->preview->id}"))->dontRelease()->expireAfter($this->timeout)];
    }

    /**
     * Put the files design changes touched through the app's own
     * formatters, so its format check passes, and commit what changed.
     * Only the newest version is formatted, and only while the preview
     * still shows it: a newer change asks again once it is shown.
     */
    public function handle(ProjectRepository $repository, FormatAppFiles $formatAppFiles, FormattedRevisions $formatted): void
    {
        $preview = $this->preview->fresh();

        if ($preview === null || ! $preview->editable || $preview->status !== PreviewStatus::Ready || $preview->workspace === null || $preview->revision !== $this->revision) {
            return;
        }

        $project = $preview->project;

        if ($repository->head($project, $preview->branch()) !== $this->revision) {
            return;
        }

        $paths = self::paths($preview);
        $files = [];

        foreach ($paths as $path) {
            $contents = $repository->show($project, $this->revision, $path);

            if ($contents !== null) {
                $files[$path] = $contents;
            }
        }

        $changed = array_filter(
            $formatAppFiles->handle($preview->workspace, $files),
            fn (string $contents, string $path) => $contents !== $files[$path],
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed !== []) {
            try {
                $sha = $repository->commitFiles($project, $this->revision, $changed, 'Format the code of recent design changes', null, $preview->branch());

                // An edit the owner sends on the version before continues.
                $formatted->record($project, $this->revision, $sha);
            } catch (RepositoryConflict) {
                // The owner changed the app meanwhile; that change asks again.
                return;
            }
        }

        Cache::lock(self::key($preview).':lock', 10)->block(5, function () use ($preview, $paths) {
            Cache::put(self::key($preview), array_values(array_diff(self::paths($preview), $paths)), now()->addDay());
        });
    }

    /**
     * @return list<string>
     */
    protected static function paths(Preview $preview): array
    {
        $paths = Cache::get(self::key($preview), []);

        return is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [];
    }

    protected static function key(Preview $preview): string
    {
        return "previews:{$preview->id}:format-paths";
    }
}
