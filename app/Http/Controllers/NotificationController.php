<?php

namespace App\Http\Controllers;

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

        // Built now, not kept: the address names the change by its UUID.
        $featureRequest = FeatureRequest::query()->whereKey($notification->data['feature_request_id'] ?? null)->first();

        return $featureRequest === null
            ? to_route('projects.index')
            : to_route('projects.show', ['project' => $featureRequest->project, 'change' => $featureRequest->uuid]);
    }
}
