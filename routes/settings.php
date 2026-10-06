<?php

use App\Http\Controllers\BillingPlanController;
use App\Http\Controllers\BillingPortalController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\DetailLevelController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ToolController;
use App\Http\Middleware\BlockWhileSignedInAsSomeone;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->middleware(BlockWhileSignedInAsSomeone::class)->name('profile.update');
    Route::patch('settings/detail-level', DetailLevelController::class)->name('detail-level.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->middleware(BlockWhileSignedInAsSomeone::class)->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware([BlockWhileSignedInAsSomeone::class, RequirePassword::class])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware([BlockWhileSignedInAsSomeone::class, 'throttle:6,1'])
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');
    Route::post('settings/billing/plan', [BillingPlanController::class, 'store'])->middleware([BlockWhileSignedInAsSomeone::class, 'throttle:6,1'])->name('billing.plan.store');
    Route::get('settings/billing/portal', BillingPortalController::class)->middleware(BlockWhileSignedInAsSomeone::class)->name('billing.portal');

    Route::get('settings/tools', [ToolController::class, 'edit'])->name('tools.edit');
    Route::delete('settings/tools/{client}', [ToolController::class, 'destroy'])->middleware(BlockWhileSignedInAsSomeone::class)->name('tools.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
