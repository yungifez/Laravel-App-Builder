<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Developers\ClaimDeveloperReview;
use App\Http\Controllers\Controller;
use App\Models\DeveloperReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A developer takes an owner's question, or gives it back.
 */
class DeveloperReviewClaimController extends Controller
{
    /**
     * Take the question.
     */
    public function store(Request $request, DeveloperReview $developerReview, ClaimDeveloperReview $claimDeveloperReview): RedirectResponse
    {
        $claimDeveloperReview->handle($developerReview, $request->user());

        return to_route('operations.developer-reviews.show', $developerReview);
    }

    /**
     * Give the question back for someone else to take.
     */
    public function destroy(DeveloperReview $developerReview, ClaimDeveloperReview $claimDeveloperReview): RedirectResponse
    {
        $claimDeveloperReview->release($developerReview);

        return to_route('operations.developer-reviews.index');
    }
}
