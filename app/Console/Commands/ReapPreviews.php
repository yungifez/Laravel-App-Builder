<?php

namespace App\Console\Commands;

use App\Actions\Previews\FindStoppedPreviews;
use App\Actions\Previews\StopPreview;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('previews:reap')]
#[Description('Stop previews that are past their maximum age, idle too long, stuck starting, or no longer answer')]
class ReapPreviews extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StopPreview $stopPreview, FindStoppedPreviews $findStoppedPreviews): int
    {
        $idleSince = now()->subMinutes((int) config('builder.preview.idle_minutes'));
        $stopped = 0;

        Preview::query()
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<', now())
                ->orWhere(fn (Builder $query) => $query->where('status', PreviewStatus::Ready)->where('last_seen_at', '<', $idleSince)))
            ->each(function (Preview $preview) use ($stopPreview, &$stopped) {
                try {
                    $stopPreview->handle($preview, __('The preview was stopped after it expired or sat idle.'));
                    $stopped++;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->components->error("Could not stop preview [{$preview->id}]: {$exception->getMessage()}");
                }
            });

        // A preview whose app stopped on our side still counts as running,
        // and visits keep it from counting as idle. Mark it stopped, so the
        // owner sees it stopped and can start it again.
        $findStoppedPreviews->handle(Preview::query()->where('status', PreviewStatus::Ready)->get())
            ->each(function (Preview $preview) use ($stopPreview, &$stopped) {
                rescue(function () use ($preview, $stopPreview, &$stopped) {
                    $stopPreview->handle($preview, __('This is our fault: the app stopped on our side. Start it again.'));
                    $stopped++;
                });
            });

        $this->components->info("Stopped {$stopped} preview(s).");

        return self::SUCCESS;
    }
}
