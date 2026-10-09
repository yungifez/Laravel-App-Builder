<?php

namespace App\Features;

use App\Models\Run;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Hand the owner's new tool connection to the next full page they load,
 * once. It is not flashed in the session: a poll that ends while the slow
 * hand-over runs writes the session back without the flash, and the first
 * connection was lost. Polls ask only for what they show, so they never
 * take it.
 */
class WorkerConnection
{
    /**
     * How long the connection waits for the page, in minutes.
     */
    public const MINUTES = 5;

    /**
     * Keep the connection for the owner's next page.
     */
    public function keep(User $owner, Run $run, string $token): void
    {
        Cache::put($this->key($owner), Crypt::encrypt(['run' => $run->uuid, 'token' => $token]), now()->addMinutes(self::MINUTES));
    }

    /**
     * Take the kept connection, so it shows only once.
     *
     * @return array{run: string, token: string}|null
     */
    public function take(User $owner): ?array
    {
        $kept = Cache::pull($this->key($owner));

        if (! is_string($kept)) {
            return null;
        }

        /** @var array{run: string, token: string} */
        return Crypt::decrypt($kept);
    }

    protected function key(User $owner): string
    {
        return "worker-connection:{$owner->id}";
    }
}
