<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectCarefulAreaUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ProjectCarefulAreaController extends Controller
{
    /**
     * Record whether the owner wants changes to an area held to more of
     * what the checks find (strict mode).
     */
    public function update(ProjectCarefulAreaUpdateRequest $request, Project $project): RedirectResponse
    {
        $area = $request->string('area')->value();
        $others = array_values(array_diff($project->careful_areas ?? [], [$area]));

        $project->forceFill(['careful_areas' => $request->boolean('careful') ? [...$others, $area] : $others])->save();

        return back();
    }
}
