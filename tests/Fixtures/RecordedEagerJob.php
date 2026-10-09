<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A job of the app RecordedApp stands in for. It asks if it ran before
 * and stops when it did, and it marks that before it sends its email. It
 * takes the mark back when the email cannot be sent only when it is asked
 * to.
 */
class RecordedEagerJob implements ShouldQueue
{
    use Queueable;

    public const PATH = 'tests/Fixtures/RecordedEagerJob.php';

    public function __construct(public bool $takesBack = false) {}

    public function handle(): void
    {
        if (DB::table('users')->where('name', 'Welcomed')->exists()) {
            return;
        }

        DB::table('users')->where('name', 'Eager')->update(['name' => 'Welcomed']);

        try {
            Mail::raw('Welcome', fn ($message) => $message->to('owner@example.com'));
        } catch (Throwable $exception) {
            if ($this->takesBack) {
                DB::table('users')->where('name', 'Welcomed')->update(['name' => 'Eager']);
            }

            throw $exception;
        }
    }
}
