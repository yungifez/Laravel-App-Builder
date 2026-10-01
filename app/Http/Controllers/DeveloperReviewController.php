<?php

namespace App\Http\Controllers;

use App\Actions\Developers\AskDeveloper;
use App\Http\Requests\DeveloperReviewStoreRequest;
use App\Models\DeveloperReview;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DeveloperReviewController extends Controller
{
    /**
     * Show what the owner asked our developers, what they said, and the
     * form to ask again.
     */
    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('update', $project);

        // A link may name a change; anything but one of this app's is ignored.
        $change = Str::isUuid($request->string('change')->value())
            ? $project->featureRequests()->where('uuid', $request->string('change'))->first()
            : null;

        return Inertia::render('projects/Developers', [
            'project' => ['id' => $project->uuid, 'name' => $project->name],
            'change' => $change === null ? null : ['id' => $change->uuid, 'prompt' => $change->prompt],
            'reviews' => $project->developerReviews()->with(['featureRequest', 'developer'])->latest('id')->get()
                ->map(fn (DeveloperReview $review) => [
                    'id' => $review->uuid,
                    'question' => $review->question,
                    'change' => $review->featureRequest === null ? null : ['id' => $review->featureRequest->uuid, 'prompt' => $review->featureRequest->prompt],
                    'asked_at' => $review->created_at?->toIso8601String(),
                    'waiting' => $review->waiting(),
                    'withdrawn' => $review->withdrawn_at !== null,
                    'developer' => $review->developer?->name,
                    'answer' => $review->answer,
                    'answered_at' => $review->answered_at?->toIso8601String(),
                    'guidance_kept_at' => $review->guidance_kept_at?->toIso8601String(),
                    'kept_guidance' => $review->kept_guidance,
                ])->all(),
        ]);
    }

    /**
     * Ask one of our developers to look at the app, or at one of its changes.
     */
    public function store(DeveloperReviewStoreRequest $request, Project $project, AskDeveloper $askDeveloper): RedirectResponse
    {
        $change = $request->filled('change')
            ? FeatureRequest::query()->where('uuid', $request->string('change'))->firstOrFail()
            : null;

        $askDeveloper->handle($project, $request->user(), $request->string('question')->value(), $change);

        return to_route('projects.developers.index', $project);
    }

    /**
     * Take the question back while it still waits for a developer.
     */
    public function destroy(DeveloperReview $developerReview): RedirectResponse
    {
        Gate::authorize('update', $developerReview->project);

        if ($developerReview->waiting()) {
            $developerReview->update(['withdrawn_at' => now()]);
        }

        return to_route('projects.developers.index', $developerReview->project);
    }
}
