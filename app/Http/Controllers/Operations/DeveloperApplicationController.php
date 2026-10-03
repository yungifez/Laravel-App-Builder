<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Developers\DecideDeveloperApplication;
use App\Http\Controllers\Controller;
use App\Models\DeveloperApplication;
use App\Models\DeveloperReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where operators decide who may answer owners' questions.
 */
class DeveloperApplicationController extends Controller
{
    /**
     * List the requests, the ones still waiting first.
     */
    public function index(): Response
    {
        $applications = DeveloperApplication::query()
            ->with(['user', 'decider'])
            ->orderByRaw('approved_at is not null or declined_at is not null')
            ->latest('updated_at')
            ->paginate(30);

        $answered = DeveloperReview::query()
            ->whereIn('answered_by', $applications->pluck('user_id'))
            ->whereNotNull('answered_at')
            ->selectRaw('answered_by, count(*) as answers')
            ->groupBy('answered_by')
            ->pluck('answers', 'answered_by');

        return Inertia::render('operations/Developers', [
            'applications' => $applications->through(fn (DeveloperApplication $application) => [
                'id' => $application->id,
                'person' => $application->user->name,
                'email' => $application->user->email,
                'user_id' => $application->user_id,
                'about' => $application->about,
                'link' => $application->link,
                'status' => match (true) {
                    $application->approved() => 'approved',
                    $application->waiting() => 'waiting',
                    default => 'declined',
                },
                'answered' => (int) ($answered[$application->user_id] ?? 0),
                'decided_by' => $application->decider?->name,
                'applied_at' => $application->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Approve or decline a request, or take an approval back.
     */
    public function update(Request $request, DeveloperApplication $developerApplication, DecideDeveloperApplication $decideDeveloperApplication): RedirectResponse
    {
        $validated = $request->validate(['approved' => ['required', 'boolean']]);

        $decideDeveloperApplication->handle($developerApplication, $request->user(), (bool) $validated['approved']);

        return back();
    }
}
