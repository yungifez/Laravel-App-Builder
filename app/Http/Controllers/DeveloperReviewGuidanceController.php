<?php

namespace App\Http\Controllers;

use App\Actions\Developers\KeepDeveloperGuidance;
use App\Models\DeveloperReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeveloperReviewGuidanceController extends Controller
{
    /**
     * Keep the developer's guidance the owner chose, so every later change
     * follows it.
     */
    public function store(Request $request, DeveloperReview $developerReview, KeepDeveloperGuidance $keepDeveloperGuidance): RedirectResponse
    {
        Gate::authorize('update', $developerReview->project);

        $validated = $request->validate([
            'points' => ['required', 'array', 'min:1'],
            'points.*' => ['integer', 'min:0'],
        ], ['points.required' => __('Choose the guidance to keep.')]);

        $keepDeveloperGuidance->handle($developerReview, array_values(array_map(intval(...), $validated['points'])));

        return to_route('projects.developers.index', $developerReview->project);
    }
}
