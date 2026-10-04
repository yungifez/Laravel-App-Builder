<?php

namespace App\Http\Controllers;

use App\VisualEditing\SampleDesign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SampleDesignController extends Controller
{
    /**
     * Show the designer on a sample page anyone can try. Each visit starts
     * from the sample as it ships, so no one sees another visitor's edits.
     */
    public function show(Request $request, SampleDesign $sample): Response
    {
        if (! $request->header('X-Inertia-Partial-Data')) {
            $sample->start();
        }

        return Inertia::render('try/Designer', [
            'preview' => fn () => $sample->preview($request->getSchemeAndHttpHost()),
            'edits' => fn () => $sample->edits(),
            'colors' => fn () => $sample->colors(),
            'designEdits' => null,
            'element' => Inertia::optional(fn () => $sample->inspect($request->query('target'))),
        ]);
    }

    /**
     * Start again from the sample as it ships.
     */
    public function destroy(SampleDesign $sample): RedirectResponse
    {
        $sample->start();

        return back();
    }
}
