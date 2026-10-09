<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * A listener of RecordedEvent. It only works after RecordedMarksReady ran:
 * one way it sends nothing before that, the other way it fails.
 */
class RecordedTellsOwner
{
    public function handle(RecordedEvent $event): void
    {
        if (DB::table('users')->where('name', 'Ready')->exists()) {
            Mail::raw('Ready', fn ($message) => $message->to('owner@example.com'));
        }
    }

    public function strict(RecordedEvent $event): void
    {
        if (DB::table('users')->where('name', 'Ready')->doesntExist()) {
            throw new RuntimeException('Nothing is ready.');
        }
    }
}
