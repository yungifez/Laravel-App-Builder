<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ChangeThemeColor;
use App\Http\Requests\ThemeColorStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ThemeColorController extends Controller
{
    /**
     * Change one of the app's theme colours.
     */
    public function store(ThemeColorStoreRequest $request, Project $project, ChangeThemeColor $changeThemeColor): RedirectResponse
    {
        $changeThemeColor->handle(
            $request->preview(),
            $request->user(),
            $request->validated('mode'),
            $request->validated('token'),
            strtolower($request->validated('color')),
            $request->validated('revision'),
        );

        return back();
    }
}
