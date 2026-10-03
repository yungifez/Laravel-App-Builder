<?php

namespace App\Http\Controllers;

use App\Actions\Developers\ApplyAsDeveloper;
use App\Http\Requests\DeveloperApplicationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a developer asks to answer owners' questions (architecture §29.3).
 */
class DeveloperApplicationController extends Controller
{
    /**
     * Say what the work is, and show the form or where the request stands.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $application = $user?->developerApplication;

        return Inertia::render('public/Developers', [
            'signedIn' => $user !== null,
            'verified' => $user?->hasVerifiedEmail() ?? false,
            'operator' => (bool) $user?->can('viewOperations'),
            'application' => $application === null ? null : [
                'about' => $application->about,
                'link' => $application->link,
                'status' => match (true) {
                    $application->approved() => 'approved',
                    $application->waiting() => 'waiting',
                    default => 'declined',
                },
                'applied_at' => $application->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Bring someone who just signed in back to the form.
     */
    public function create(): RedirectResponse
    {
        return redirect()->to(route('developers').'#apply');
    }

    /**
     * Keep the request for an operator to decide.
     */
    public function store(DeveloperApplicationRequest $request, ApplyAsDeveloper $applyAsDeveloper): RedirectResponse
    {
        $applyAsDeveloper->handle(
            $request->user(),
            $request->string('about')->value(),
            $request->filled('link') ? $request->string('link')->value() : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent. We will email you when we have decided.')]);

        return to_route('developers');
    }
}
