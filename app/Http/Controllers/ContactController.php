<?php

namespace App\Http\Controllers;

use App\Actions\Contact\SendContactMessage;
use App\Http\Requests\ContactMessageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Honeypot\Honeypot;

class ContactController extends Controller
{
    /**
     * Show the contact form, filled in for someone signed in.
     */
    public function show(Request $request, Honeypot $honeypot): Response
    {
        return Inertia::render('public/Contact', [
            // "name" is the app's own name on every page, so the sender's
            // details have their own keys.
            'senderName' => $request->user()?->name,
            'senderEmail' => $request->user()?->email,
            'honeypot' => $honeypot->toArray(),
        ]);
    }

    /**
     * Send a message to the people who run the platform.
     */
    public function store(ContactMessageRequest $request, SendContactMessage $sendContactMessage): RedirectResponse
    {
        /** @var array{name: string, email: string, message: string} $fields */
        $fields = $request->safe()->only(['name', 'email', 'message']);
        $sendContactMessage->handle($fields, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent. We will reply to your email.')]);

        return to_route('contact');
    }
}
