<?php

namespace App\Actions\Contact;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageReceived;
use Illuminate\Support\Facades\Notification;

class SendContactMessage
{
    /**
     * Keep a contact message and email it to the operators, so a message
     * is never lost when mail fails.
     *
     * @param  array{name: string, email: string, message: string}  $fields
     */
    public function handle(array $fields, ?User $sender = null): ContactMessage
    {
        $message = ContactMessage::create([...$fields, 'user_id' => $sender?->id]);

        /** @var list<string> $operators */
        $operators = config('operations.operators');

        if ($operators !== []) {
            Notification::route('mail', $operators)->notify(new ContactMessageReceived($message));
        }

        return $message;
    }
}
