<?php

namespace App\Actions\Previews;

use App\Models\Preview;
use App\Models\Project;
use App\Previews\SavedTables;
use Illuminate\Support\Facades\Cache;

class ReadPreviewData
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Get the tables the app on show keeps its data in, with how many rows
     * each holds, or null while the app does not run. They are read through
     * the app itself, from the database it runs with.
     *
     * @return list<array{name: string, words: string, rows: int|null, own: bool}>|null
     */
    public function handle(Project $project): ?array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview === null) {
            return null;
        }

        // The builder asks every few seconds while the owner looks.
        return Cache::remember(self::key($preview), now()->addSeconds(4), fn () => SavedTables::in((string) rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', 'artisan', 'db:show', '--json', '--counts', '--no-interaction'], 60, ''),
            '',
            report: false,
        )));
    }

    /**
     * Forget what was read, after the data changed.
     */
    public static function forget(Preview $preview): void
    {
        Cache::forget(self::key($preview));
    }

    protected static function key(Preview $preview): string
    {
        return "previews:{$preview->id}:data";
    }
}
