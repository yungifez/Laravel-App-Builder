<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Teams\UpdateTeamName;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TeamUpdateRequest;
use App\Http\Resources\TeamMemberResource;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Show the settings page for the user's current team.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_if($team === null, 404);

        Gate::authorize('view', $team);

        return Inertia::render('settings/Team', [
            'team' => $team->only('id', 'name', 'personal_team'),
            'members' => TeamMemberResource::collection($team->members()->orderBy('name')->get())->resolve($request),
            'teams' => $user->teams()->orderBy('name')->get(['teams.id', 'teams.name'])
                ->map(fn (Team $team) => $team->only('id', 'name')),
            'assignableRoles' => collect(TeamRole::assignable())
                ->map(fn (TeamRole $role) => ['value' => $role->value, 'label' => $role->label()]),
            'can' => [
                'updateTeam' => $user->can('update', $team),
                'updateMemberRoles' => $user->can('updateMemberRole', $team),
                'removeMembers' => $user->can('removeMember', $team),
            ],
        ]);
    }

    /**
     * Update the team's details.
     */
    public function update(TeamUpdateRequest $request, Team $team, UpdateTeamName $updateTeamName): RedirectResponse
    {
        $updateTeamName->handle($team, $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('teams.edit');
    }
}
