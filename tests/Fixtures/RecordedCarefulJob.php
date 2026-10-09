<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * A job of the app RecordedApp stands in for. It asks if it ran before
 * and stops when it did, but it marks that only after it sent its email.
 */
class RecordedCarefulJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (DB::table('users')->where('name', 'Told')->exists()) {
            return;
        }

        Mail::raw('Done', fn ($message) => $message->to('owner@example.com'));
        DB::table('users')->where('name', 'Careful')->update(['name' => 'Told']);
    }
}
