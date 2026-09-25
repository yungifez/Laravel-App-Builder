<?php

namespace App\Http\Controllers;

use App\Actions\Teams\SwitchCurrentTeam;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CurrentTeamController extends Controller
{
    /**
     * Switch the user's current team.
     */
    public function update(Request $request, Team $team, SwitchCurrentTeam $switchCurrentTeam): RedirectResponse
    {
        Gate::authorize('view', $team);

        $switchCurrentTeam->handle($request->user(), $team);

        return to_route('teams.edit');
    }
}
