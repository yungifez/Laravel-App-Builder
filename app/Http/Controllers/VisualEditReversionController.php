<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\RevertVisualEdit;
use App\Models\VisualEdit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VisualEditReversionController extends Controller
{
    /**
     * Undo a change to how an element looks.
     */
    public function store(Request $request, VisualEdit $visualEdit, RevertVisualEdit $revertVisualEdit): RedirectResponse
    {
        Gate::authorize('update', $visualEdit->project);

        $revertVisualEdit->handle($visualEdit, $request->user());

        return back();
    }
}
