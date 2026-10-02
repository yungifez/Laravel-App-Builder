<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A job of the app RecordedApp stands in for. It catches an email that
 * cannot be sent and ends as if the email was sent, unless it is asked to
 * record the failure.
 */
class RecordedHushedJob implements ShouldQueue
{
    use Queueable;

    public const PATH = 'tests/Fixtures/RecordedHushedJob.php';

    public function __construct(public bool $recorded = false) {}

    public function handle(): void
    {
        DB::table('users')->where('id', 0)->update(['name' => 'Hushed']);

        try {
            Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));
        } catch (Throwable $exception) {
            if ($this->recorded) {
                report($exception);
            }
        }
    }
}
