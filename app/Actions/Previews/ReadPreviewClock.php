<?php

namespace App\Actions\Previews;

use App\Models\Preview;
use App\Models\Project;
use App\Previews\PreviewClock;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class ReadPreviewClock
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private WorkspaceManager $workspaces) {}

    /**
     * Get how far ahead the app on show is, what day that makes it there,
     * whether a jump is under way, and what the last jump ran. Null while
     * the app does not run or cannot be moved.
     *
     * @return array{ahead: int, now: string, moving: bool, ran: list<array{words: string, times: int, failed: int}>, error: string|null}|null
     */
    public function handle(Project $project): ?array
    {
        $preview = $this->readPreviewLog->preview($project);
        $workspace = $preview?->workspace;

        if ($preview === null || $workspace === null || ! Config::boolean('builder.preview.recorder.enabled') || ! Config::boolean('builder.preview.clock.enabled')) {
            return null;
        }

        $directory = trim(Config::string('builder.preview.recorder.directory'), '/');
        $ahead = PreviewClock::ahead((string) rescue(fn () => $this->workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, "{$directory}/clock.json"), '', report: false));
        /** @var array{ran: list<array{words: string, times: int, failed: int}>, error: string|null} $outcome */
        $outcome = Cache::get(self::outcomeKey($preview), ['ran' => [], 'error' => null]);

        return [
            'ahead' => $ahead,
            'now' => now()->addSeconds($ahead)->toIso8601String(),
            'moving' => Cache::has(self::movingKey($preview)),
            ...$outcome,
        ];
    }

    /**
     * Where a jump under way is marked, so only one runs at a time.
     */
    public static function movingKey(Preview $preview): string
    {
        return "previews:{$preview->id}:clock:moving";
    }

    /**
     * Where what the last jump ran is kept.
     */
    public static function outcomeKey(Preview $preview): string
    {
        return "previews:{$preview->id}:clock:outcome";
    }
}
