<?php

namespace App\Http\Controllers;

use App\VisualEditing\SampleDesign;
use App\VisualEditing\TailwindClasses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SampleEditController extends Controller
{
    /**
     * Change how a part of the sample looks.
     */
    public function store(Request $request, SampleDesign $sample): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'max:255'],
            'expected' => ['present', 'nullable', 'string', 'max:4000'],
            'device' => ['required', Rule::in(TailwindClasses::DEVICES)],
            'changes' => ['required', 'array:'.implode(',', TailwindClasses::PROPERTIES), 'min:1'],
            'changes.*' => ['nullable'],
        ]);

        $sample->look($validated['target'], (string) $validated['expected'], $validated['device'], $validated['changes']);

        return back();
    }
}
