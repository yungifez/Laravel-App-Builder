<?php

namespace App\Http\Controllers;

use App\VisualEditing\SampleDesign;
use Illuminate\Http\RedirectResponse;

class SampleEditReversionController extends Controller
{
    /**
     * Undo an edit to the sample.
     */
    public function store(string $edit, SampleDesign $sample): RedirectResponse
    {
        $sample->step($edit, undo: true);

        return back();
    }

    /**
     * Make an undone edit to the sample again.
     */
    public function destroy(string $edit, SampleDesign $sample): RedirectResponse
    {
        $sample->step($edit, undo: false);

        return back();
    }
}
