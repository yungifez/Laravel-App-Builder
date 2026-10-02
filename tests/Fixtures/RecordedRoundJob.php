<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * A job of the app RecordedApp stands in for. It sends one email to each
 * of two people from one line. When it is asked to, it dispatches one job
 * for each person in its place.
 */
class RecordedRoundJob implements ShouldQueue
{
    use Queueable;

    public const PATH = 'tests/Fixtures/RecordedRoundJob.php';

    public function __construct(public bool $apart = false, public ?string $to = null) {}

    public function handle(): void
    {
        if ($this->to !== null) {
            Mail::raw('Round', fn ($message) => $message->to((string) $this->to));

            return;
        }

        foreach (['first@example.com', 'second@example.com'] as $to) {
            if ($this->apart) {
                self::dispatch(to: $to);

                continue;
            }

            Mail::raw('Round', fn ($message) => $message->to($to));
        }
    }
}
