<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * A job of the app RecordedApp stands in for. It thanks a person by
 * email. It was given the address, or it takes it from the request it
 * was dispatched in: the person who is signed in, what they sent, or
 * their session.
 */
class RecordedPersonalJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $from, public ?string $email = null) {}

    public function handle(): void
    {
        $email = match ($this->from) {
            'person' => auth()->user()?->email,
            'sent' => request()->input('email'),
            'session' => session('email'),
            default => $this->email,
        };

        throw_if($email === null, new RuntimeException('No one to thank.'));

        Mail::raw('Thanks', fn ($message) => $message->to($email));
    }
}
