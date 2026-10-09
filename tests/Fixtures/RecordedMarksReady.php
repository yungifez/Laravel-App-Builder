<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * A listener of RecordedEvent. It saves what RecordedTellsOwner asks for.
 */
class RecordedMarksReady
{
    public function handle(RecordedEvent $event): void
    {
        DB::table('users')->where('name', 'New')->update(['name' => 'Ready']);
    }
}
