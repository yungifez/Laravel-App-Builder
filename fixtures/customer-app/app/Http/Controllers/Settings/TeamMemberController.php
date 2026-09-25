<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Teams\RemoveTeamMember;
use App\Actions\Teams\UpdateTeamMemberRole;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TeamMemberDestroyRequest;
use App\Http\Requests\Settings\TeamMemberUpdateRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Change a team member's role.
     */
    public function update(TeamMemberUpdateRequest $request, Team $team, User $member, UpdateTeamMemberRole $updateTeamMemberRole): RedirectResponse
    {
        $updateTeamMemberRole->handle($team, $member, TeamRole::from($request->validated('role')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('teams.edit');
    }

    /**
     * Remove a member from the team.
     */
    public function destroy(TeamMemberDestroyRequest $request, Team $team, User $member, RemoveTeamMember $removeTeamMember): RedirectResponse
    {
        $removeTeamMember->handle($team, $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit');
    }
}
