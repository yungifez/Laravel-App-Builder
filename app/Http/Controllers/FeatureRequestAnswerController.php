<?php

namespace App\Http\Controllers;

use App\Actions\Runs\AnswerRunQuestion;
use App\Http\Requests\AnswerQuestionRequest;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;

class FeatureRequestAnswerController extends Controller
{
    /**
     * Answer the question asked before building, and carry on.
     */
    public function store(AnswerQuestionRequest $request, FeatureRequest $featureRequest, AnswerRunQuestion $answerRunQuestion): RedirectResponse
    {
        $answerRunQuestion->handle(
            $featureRequest,
            $request->user(),
            $request->validated('answer'),
            $request->boolean('more_questions'),
        );

        return back();
    }
}
