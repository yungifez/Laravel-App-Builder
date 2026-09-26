---
capability: membership
summary: Who is in a team and what their role lets them do.
paths:
    - app/Actions/Teams/RemoveTeamMember.php
    - app/Actions/Teams/UpdateTeamMemberRole.php
    - app/Enums/TeamRole.php
    - app/Models/Membership.php
    - app/Models/Concerns/HasTeams.php
    - app/Policies/TeamPolicy.php
    - app/Http/Controllers/Settings/TeamMemberController.php
    - app/Http/Requests/Settings/TeamMemberDestroyRequest.php
    - app/Http/Requests/Settings/TeamMemberUpdateRequest.php
    - config/teams.php
    - tests/Feature/Teams/RemoveTeamMemberTest.php
    - tests/Feature/Teams/TeamMemberRoleTest.php
behaviors:
    - key: change-role
      name: Change a member's role
    - key: remove-member
      name: Remove a member
effects:
    - to: teams
      strength: possible
      reason: Roles decide who may rename a team.
      source: analysis
---

# Membership

## Rules

- A team has exactly one owner. The owner's role cannot be changed and the
  owner cannot be removed.
- Owners and admins can change other members' roles to admin or member.
- Owners and admins can remove members.
- What each role may do is set in one place (config/teams.php).

## What each behaviour does

### Change a member's role

In the team settings, an owner or admin makes a member an admin, or an admin a
member.

### Remove a member

An owner or admin removes someone from the team; they lose access to it.
