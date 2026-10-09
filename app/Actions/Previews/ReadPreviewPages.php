<?php

namespace App\Actions\Previews;

use App\Models\Project;
use App\Previews\AppPages;
use Illuminate\Support\Facades\Cache;

class ReadPreviewPages
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Get the pages of the app on show, or null while it does not run. They
     * are read through the app itself.
     *
     * @return list<array{path: string, words: string, signed_in: bool}>|null
     */
    public function handle(Project $project): ?array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview === null) {
            return null;
        }

        // The pages change only with the app's code.
        return Cache::remember("previews:{$preview->id}:pages", now()->addSeconds(20), fn () => AppPages::in((string) rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', 'artisan', 'route:list', '--json', '--method=GET', '--no-interaction'], 60, ''),
            '',
            report: false,
        )));
    }
}
