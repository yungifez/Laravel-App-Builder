---
capability: teams
summary: People work in teams; everyone has a personal team and can switch between their teams.
paths:
    - app/Actions/Teams/CreateTeam.php
    - app/Actions/Teams/SwitchCurrentTeam.php
    - app/Actions/Teams/UpdateTeamName.php
    - app/Models/Team.php
    - app/Http/Controllers/CurrentTeamController.php
    - app/Http/Controllers/Settings/TeamController.php
    - app/Http/Requests/Settings/TeamUpdateRequest.php
    - resources/js/pages/settings/Team.vue
    - tests/Feature/Teams/PersonalTeamTest.php
    - tests/Feature/Teams/SwitchCurrentTeamTest.php
    - tests/Feature/Teams/TeamSettingsTest.php
    - tests/Feature/Teams/UpdateTeamTest.php
behaviors:
    - key: personal-team
      name: Get a personal team on sign-up
    - key: switch-team
      name: Switch the current team
    - key: rename-team
      name: Rename a team
effects:
    - to: membership
      strength: strong
      reason: Creating a team makes its creator the owner member.
      source: analysis
---

# Teams

## Rules

- Everyone gets a personal team when they sign up, and is its owner.
- Owners and admins can rename a team.

## What each behaviour does

### Get a personal team on sign-up

When someone registers, a team named after them is created and they become its
owner.

### Switch the current team

Someone in several teams chooses which one they are working in.

### Rename a team

Owners and admins change the team's name in the team settings.
