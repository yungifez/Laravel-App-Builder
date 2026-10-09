<?php

namespace App\Actions\Context;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Operations\SummarizeSpend;
use App\Features\SpendPause;
use App\Jobs\UpdateBehindNotes;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Validation\ValidationException;

class RequestNotesUpdate
{
    public function __construct(private SummarizeSpend $summarizeSpend, private MeasureUsage $measureUsage) {}

    /**
     * Get the parts whose notes a kept change left behind, while the owner
     * may still ask to bring them up to date: the change is kept, not
     * undone, and its notes are not being updated now or updated already.
     *
     * @return list<string>
     */
    public static function behind(FeatureRequest $featureRequest): array
    {
        $run = $featureRequest->latestRun;

        if ($featureRequest->commit_sha === null || $featureRequest->reverted_at !== null || $featureRequest->branch() === null || $run?->review === null) {
            return [];
        }

        return in_array(self::state($run), ['working', 'done'], true) ? [] : $run->review['classification']['notes_behind'];
    }

    /**
     * Get where bringing the notes up to date stands: null before the owner
     * asked, then working, done or failed, from the run's latest event
     * about it.
     */
    public static function state(Run $run): ?string
    {
        return match ($run->events()->whereIn('type', ['notes_update_requested', 'notes_updated', 'notes_update_failed'])->reorder('sequence', 'desc')->value('type')) {
            'notes_update_requested' => 'working',
            'notes_updated' => 'done',
            'notes_update_failed' => 'failed',
            default => null,
        };
    }

    /**
     * Ask the reviewer's model to bring the notes of the parts a kept
     * change left behind up to date, in the background. It is AI use like
     * a change, so it waits while our spend is paused or the owner's plan
     * is used up.
     *
     * @throws ValidationException when nothing is behind, or AI use is paused or used up.
     */
    public function handle(FeatureRequest $featureRequest): void
    {
        if (self::behind($featureRequest) === []) {
            throw ValidationException::withMessages(['notes' => __('These notes are not waiting for an update.')]);
        }

        if ($this->summarizeSpend->dailyLimitReached()) {
            throw ValidationException::withMessages(['notes' => SpendPause::message()]);
        }

        $usage = $this->measureUsage->handle($featureRequest->project->owner);

        if ($usage['reached']) {
            throw ValidationException::withMessages(['notes' => __('You have used all the AI use your plan includes this month. It starts again on :date, or you can move to a bigger plan in Settings. Nothing in your app changed.', [
                'date' => $usage['resets_at']->isoFormat('D MMMM'),
            ])]);
        }

        $run = $featureRequest->latestRun;
        $run->recordEvent('notes_update_requested', ['areas' => self::behind($featureRequest)]);

        UpdateBehindNotes::dispatch($run)->afterCommit();
    }
}
