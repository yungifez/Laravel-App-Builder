<?php

namespace App\Http\Controllers;

use App\VisualEditing\MotionClasses;
use App\VisualEditing\SampleDesign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SampleMotionController extends Controller
{
    /**
     * Change how a part of the sample moves.
     */
    public function store(Request $request, SampleDesign $sample): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:255'],
            'expected' => ['present', 'nullable', 'string', 'max:2000'],
            'motion.entrance' => ['required', Rule::in(MotionClasses::ENTRANCES)],
            'motion.speed' => ['required', Rule::in(MotionClasses::SPEEDS)],
            'motion.wait' => ['required', Rule::in(MotionClasses::WAITS)],
            'motion.hover' => ['required', Rule::in(MotionClasses::HOVERS)],
            'motion.loop' => ['required', Rule::in(MotionClasses::LOOPS)],
        ]);

        $sample->motion($validated['target'], (string) $validated['expected'], $validated['motion']);

        return back();
    }
}
