<?php

namespace App\Http\Controllers;

use App\Actions\Features\AnswerFindingProposals;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeatureRequestFindingProposalController extends Controller
{
    /**
     * Answer the agent's case for keeping what one rule found: yes keeps
     * it this way, no has it fixed.
     */
    public function update(Request $request, FeatureRequest $featureRequest, string $kind, AnswerFindingProposals $answerFindingProposals): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $answerFindingProposals->handle($featureRequest, $kind, $request->validate(['agreed' => ['required', 'boolean']])['agreed'], $request->user());

        return back();
    }
}
