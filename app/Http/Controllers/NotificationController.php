<?php

namespace App\Http\Controllers;

use App\Models\DeveloperReview;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Open what a notification is about, and mark it read.
     */
    public function show(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        // Built now, not kept: the address names the app or change by its UUID.
        $asked = DeveloperReview::query()->whereKey($notification->data['asked_review_id'] ?? null)->first();

        if ($asked !== null && $request->user()->can('view', $asked)) {
            return to_route('operations.developer-reviews.show', $asked);
        }

        // An operator decided on the person's request to be a developer.
        if (isset($notification->data['developer_application'])) {
            return $request->user()->can('answerDeveloperQuestions')
                ? to_route('operations.developer-reviews.index')
                : to_route('developers');
        }

        $review = DeveloperReview::query()->whereKey($notification->data['developer_review_id'] ?? null)->first();

        if ($review !== null) {
            return to_route('projects.developers.index', $review->project);
        }

        $featureRequest = FeatureRequest::query()->whereKey($notification->data['feature_request_id'] ?? null)->first();

        return $featureRequest === null
            ? to_route('projects.index')
            : to_route('projects.show', ['project' => $featureRequest->project, 'change' => $featureRequest->uuid]);
    }
}
