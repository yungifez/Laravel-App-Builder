<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What people wrote through the contact form.
 */
class ContactMessageController extends Controller
{
    /**
     * List the messages, the ones nobody handled first.
     */
    public function index(): Response
    {
        $messages = ContactMessage::query()
            ->orderByRaw('handled_at is not null')
            ->latest('id')
            ->paginate(30);

        return Inertia::render('operations/Messages', [
            'messages' => $messages->through(fn (ContactMessage $message) => [
                'id' => $message->id,
                'name' => $message->name,
                'email' => $message->email,
                'person' => $message->user_id,
                'message' => $message->message,
                'sent_at' => $message->created_at?->toIso8601String(),
                'handled' => $message->handled_at !== null,
            ]),
        ]);
    }

    /**
     * Mark a message handled, or not handled after all.
     */
    public function update(Request $request, ContactMessage $contactMessage): RedirectResponse
    {
        $request->validate(['handled' => ['required', 'boolean']]);

        $contactMessage->update(['handled_at' => $request->boolean('handled') ? now() : null]);

        return back();
    }
}
