<?php

namespace App\Jobs;

use App\Actions\Previews\ReadPreviewClock;
use App\Actions\Previews\ReadPreviewData;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Previews\PreviewClock;
use App\Previews\ScheduledTasks;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Move the app on show a day, a week or a month ahead. Each task the
 * app's schedule would have run in that time runs at its own moment,
 * oldest first, with the app's clock set to it. Then the clock stays
 * ahead, and whoever was signed in stays signed in.
 */
class MovePreviewClock implements ShouldQueue
{
    use Queueable;

    /**
     * Each of the runs has its own limit; this is all of them together.
     */
    public int $timeout = 1800;

    /**
     * Create a new job instance.
     */
    public function __construct(public Preview $preview, public string $jump)
    {
        $this->onQueue(config('builder.preview.queue'));
    }

    /**
     * Execute the job.
     */
    public function handle(RunWorkspaceCommand $runWorkspaceCommand, WorkspaceManager $workspaces): void
    {
        $preview = $this->preview->fresh();
        $workspace = $preview?->workspace;

        if ($preview === null || $workspace === null || $preview->status !== PreviewStatus::Ready) {
            $this->finish(['ran' => [], 'error' => __('Your app stopped before it could move ahead. Start it and try again.')]);

            return;
        }

        $driver = $workspaces->driver($workspace->driver);
        $id = (string) $workspace->driver_id;
        $directory = trim(Config::string('builder.preview.recorder.directory'), '/');
        $timeout = Config::integer('builder.preview.clock.timeout');
        $run = fn (string ...$command) => $runWorkspaceCommand->handle($workspace, PreviewClock::command($directory, ...$command), $timeout, $preview->environment());
        $setClock = fn (int $ahead) => $driver->writeFile($id, "{$directory}/clock.json", PreviewClock::file($ahead));

        // Each command the schedule starts loads the recorder, and with it the clock.
        $driver->writeFile($id, "{$directory}/ini/recorder.ini", 'auto_prepend_file='.Config::string('builder.preview.recorder.prepend')."\n");

        $ahead = PreviewClock::ahead((string) rescue(fn () => $driver->readFile($id, "{$directory}/clock.json"), '', report: false));
        $listed = $run('php', 'artisan', 'schedule:list', '--json', '--no-interaction');

        if ($listed->exit_code !== 0) {
            $this->finish(['ran' => [], 'error' => __('Your app could not say what it runs on its own. See Problems for what went wrong.')]);

            return;
        }

        $tasks = ScheduledTasks::in((string) $listed->output);
        $from = Date::now()->toImmutable()->addSeconds($ahead);
        $to = PreviewClock::jump($from, $this->jump, $tasks[0]['timezone'] ?? 'UTC');
        $ran = [];

        foreach (PreviewClock::due($tasks, $from, $to, Config::integer('builder.preview.clock.each'), Config::integer('builder.preview.clock.most')) as $due) {
            $setClock(max(0, $due['at']->getTimestamp() - Date::now()->getTimestamp()));
            $result = $run('php', 'artisan', 'schedule:test', '--name='.$due['task'], '--no-interaction');
            $task = collect($tasks)->firstWhere('name', $due['task']);

            $ran[$due['task']] ??= ['words' => $task['words'] ?? $due['task'], 'times' => 0, 'failed' => 0];
            $ran[$due['task']]['times']++;
            $ran[$due['task']]['failed'] += $result->exit_code === 0 && ! $result->timed_out ? 0 : 1;
        }

        // The skipped time is added to where the clock stood, so it runs on
        // from the moment the jump was asked for.
        $skipped = $to->getTimestamp() - $from->getTimestamp();
        $setClock($ahead + $skipped);
        $driver->writeFile($id, "{$directory}/sessions.php", PreviewClock::sessions());
        $runWorkspaceCommand->handle($workspace, ['php', "{$directory}/sessions.php", (string) $skipped], $timeout, $preview->environment());

        $this->finish(['ran' => array_values($ran), 'error' => null]);
        Cache::forget("previews:{$preview->id}:schedule");
        Cache::forget("previews:{$preview->id}:log");
        Cache::forget("previews:{$preview->id}:trace");
        ReadPreviewData::forget($preview);
    }

    /**
     * Keep what the jump did for the owner, and let them jump again.
     *
     * @param  array{ran: list<array{words: string, times: int, failed: int}>, error: string|null}  $outcome
     */
    protected function finish(array $outcome): void
    {
        Cache::put(ReadPreviewClock::outcomeKey($this->preview), $outcome, now()->addDay());
        Cache::forget(ReadPreviewClock::movingKey($this->preview));
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->finish(['ran' => [], 'error' => __('Your app could not be moved ahead. This is our fault. Try again.')]);
    }
}
