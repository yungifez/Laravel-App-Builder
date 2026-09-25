<?php

use App\Http\Controllers\CurrentTeamController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TeamController;
use App\Http\Controllers\Settings\TeamMemberController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/team', [TeamController::class, 'edit'])->name('teams.edit');
    Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');

    Route::scopeBindings()->group(function () {
        Route::put('settings/teams/{team}/members/{member}', [TeamMemberController::class, 'update'])->name('team-members.update');
        Route::delete('settings/teams/{team}/members/{member}', [TeamMemberController::class, 'destroy'])->name('team-members.destroy');
    });

    Route::put('current-team/{team}', [CurrentTeamController::class, 'update'])->name('current-team.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
