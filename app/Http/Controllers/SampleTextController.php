<?php

namespace App\Http\Controllers;

use App\VisualEditing\SampleDesign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SampleTextController extends Controller
{
    /**
     * Change the words a part of the sample shows.
     */
    public function store(Request $request, SampleDesign $sample): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:255'],
            'before' => ['present', 'nullable', 'string', 'max:500'],
            'text' => ['required', 'string', 'max:500'],
        ]);

        $sample->words($validated['target'], (string) $validated['before'], $validated['text']);

        return back();
    }
}
