<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * A job of the app RecordedApp stands in for. It adds a row, sends an
 * email and changes a row, and does not ask if it ran before.
 */
class RecordedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        DB::table('users')->insert(['name' => 'Worked', 'email' => Str::random(12).'@example.com', 'password' => 'secret']);
        Mail::raw('Done', fn ($message) => $message->to('owner@example.com'));
        DB::table('users')->where('id', 0)->update(['name' => 'Done']);
    }
}
