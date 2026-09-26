---
capability: account
summary: Signing up, logging in, email verification, password and profile settings.
paths:
    - app/Actions/Fortify/*
    - app/Http/Controllers/Settings/ProfileController.php
    - app/Http/Controllers/Settings/SecurityController.php
    - app/Http/Requests/Settings/PasswordUpdateRequest.php
    - app/Http/Requests/Settings/ProfileDeleteRequest.php
    - app/Http/Requests/Settings/ProfileUpdateRequest.php
    - app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php
    - app/Models/User.php
    - resources/js/pages/auth/*
    - resources/js/pages/settings/Profile.vue
    - resources/js/pages/settings/Security.vue
    - tests/Feature/Auth/*
    - tests/Feature/Settings/*
behaviors:
    - key: sign-up
      name: Sign up
    - key: delete-account
      name: Delete my account
effects:
    - to: teams
      strength: strong
      reason: Signing up creates the person's personal team.
      source: analysis
---

# Account

## Rules

- People must verify their email address before using the app.
- Deleting an account requires the current password.
