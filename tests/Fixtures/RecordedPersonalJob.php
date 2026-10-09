<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * A job of the app RecordedApp stands in for. It thanks a person by
 * email. It was given the address or the person, or it takes the
 * address from the request it was dispatched in: the person who is
 * signed in, what they sent, or their session.
 */
class RecordedPersonalJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $from, public ?string $email = null, public ?User $person = null) {}

    public function handle(): void
    {
        $email = match ($this->from) {
            'person' => auth()->user()?->email,
            'sent' => request()->input('email'),
            'session' => session('email'),
            'model' => $this->person?->email,
            default => $this->email,
        };

        throw_if($email === null, new RuntimeException('No one to thank.'));

        Mail::raw('Thanks', fn ($message) => $message->to($email));
    }
}
