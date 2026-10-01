<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Developers\RecordDeveloperAnswer;
use App\Actions\Projects\PackProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeveloperAnswerRequest;
use App\Models\DeveloperReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Where our own developers answer the owners who asked for one
 * (architecture §29.3).
 */
class DeveloperReviewController extends Controller
{
    /**
     * List the questions, the ones still waiting first.
     */
    public function index(): Response
    {
        $reviews = DeveloperReview::query()
            ->with(['project', 'developer'])
            ->orderByRaw('answered_at is not null, withdrawn_at is not null')
            ->latest('id')
            ->paginate(30);

        return Inertia::render('operations/DeveloperReviews', [
            'reviews' => $reviews->through(fn (DeveloperReview $review) => [
                'id' => $review->uuid,
                'app' => $review->project->name,
                'question' => $review->question,
                'about_change' => $review->feature_request_id !== null,
                'asked_at' => $review->created_at?->toIso8601String(),
                'waiting' => $review->waiting(),
                'withdrawn' => $review->withdrawn_at !== null,
                'developer' => $review->developer?->name,
                'guidance_kept' => $review->guidance_kept_at !== null,
            ]),
        ]);
    }

    /**
     * Show what was written for the developer, the code, and the answer.
     */
    public function show(DeveloperReview $developerReview): Response
    {
        $change = $developerReview->featureRequest;

        return Inertia::render('operations/DeveloperReview', [
            'review' => [
                'id' => $developerReview->uuid,
                'app' => $developerReview->project->name,
                'change' => $change?->uuid,
                // Escaped: the notes and the change are text, never markup.
                'request' => Str::markdown($developerReview->bundle, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
                'has_code' => $developerReview->revision !== null,
                'has_change' => $this->changeApart($developerReview),
                'answer' => $developerReview->answer,
                'developer' => $developerReview->developer?->name,
                'answered_at' => $developerReview->answered_at?->toIso8601String(),
                'withdrawn' => $developerReview->withdrawn_at !== null,
                'guidance_kept' => $developerReview->guidance_kept_at !== null,
                'kept_guidance' => $developerReview->kept_guidance,
            ],
        ]);
    }

    /**
     * Keep the developer's answer.
     */
    public function update(DeveloperAnswerRequest $request, DeveloperReview $developerReview, RecordDeveloperAnswer $recordDeveloperAnswer): RedirectResponse
    {
        $recordDeveloperAnswer->handle(
            $developerReview,
            $request->user(),
            $request->string('summary')->value(),
            $request->string('findings')->value(),
            $request->string('guidance')->value(),
        );

        return to_route('operations.developer-reviews.show', $developerReview);
    }

    /**
     * Send what was written for the developer as a Markdown file.
     */
    public function request(DeveloperReview $developerReview): HttpResponse
    {
        return response($developerReview->bundle, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="review-request.md"',
        ]);
    }

    /**
     * Send the code the question is about, as the owner would download it.
     */
    public function code(DeveloperReview $developerReview, PackProject $packProject): BinaryFileResponse
    {
        $path = $packProject->handle($developerReview->project, $developerReview->revision) ?? abort(404);

        return response()->download($path, $packProject->folder($developerReview->project).'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Send the change that is not in the code yet, to apply on top of it.
     */
    public function change(DeveloperReview $developerReview): HttpResponse
    {
        abort_unless($this->changeApart($developerReview), 404);

        return response((string) $developerReview->featureRequest?->patch, 200, [
            'Content-Type' => 'text/x-diff; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="change.patch"',
        ]);
    }

    /**
     * Determine if the change is apart from the code download: made, but
     * not kept yet.
     */
    protected function changeApart(DeveloperReview $developerReview): bool
    {
        $change = $developerReview->featureRequest;

        return $change !== null && $change->commit_sha === null && filled($change->patch);
    }
}
