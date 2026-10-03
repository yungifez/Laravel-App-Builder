<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Operations\FindAttentionItems;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\DeveloperApplication;
use App\Models\DeveloperReview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttentionController extends Controller
{
    /**
     * Show what needs an operator's attention across every project.
     */
    public function __invoke(Request $request, FindAttentionItems $findAttentionItems): Response
    {
        $days = in_array((int) $request->query('days'), [1, 7, 30], true)
            ? (int) $request->query('days')
            : (int) config('operations.attention.window_days');

        return Inertia::render('operations/Attention', [
            'attention' => $findAttentionItems->handle($days),
            'waitingQuestions' => DeveloperReview::query()->whereNull('answered_at')->whereNull('withdrawn_at')->count(),
            'newMessages' => ContactMessage::query()->whereNull('handled_at')->count(),
            'waitingDevelopers' => DeveloperApplication::query()->whereNull('approved_at')->whereNull('declined_at')->count(),
        ]);
    }
}
