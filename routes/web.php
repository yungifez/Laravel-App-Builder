<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeatureRequestAcceptanceController;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\FeatureRequestPreviewController;
use App\Http\Controllers\FeatureRequestReversionController;
use App\Http\Controllers\FeatureRequestStepChangeController;
use App\Http\Controllers\FeatureRequestVerificationController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RunCancellationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

    Route::post('projects/{project}/feature-requests', [FeatureRequestController::class, 'store'])->name('feature-requests.store');
    Route::get('feature-requests/{featureRequest}', [FeatureRequestController::class, 'show'])->name('feature-requests.show');
    Route::post('feature-requests/{featureRequest}/step-changes', [FeatureRequestStepChangeController::class, 'store'])->name('feature-requests.step-changes.store');
    Route::post('feature-requests/{featureRequest}/verifications', [FeatureRequestVerificationController::class, 'store'])->name('feature-requests.verifications.store');
    Route::post('feature-requests/{featureRequest}/acceptance', [FeatureRequestAcceptanceController::class, 'store'])->name('feature-requests.acceptance.store');
    Route::post('feature-requests/{featureRequest}/reversion', [FeatureRequestReversionController::class, 'store'])->name('feature-requests.reversion.store');
    Route::post('feature-requests/{featureRequest}/previews', [FeatureRequestPreviewController::class, 'store'])->name('feature-requests.previews.store');
    Route::get('previews/{preview}', [PreviewController::class, 'show'])->name('previews.show');
    Route::delete('previews/{preview}', [PreviewController::class, 'destroy'])->name('previews.destroy');
    Route::post('runs/{run}/cancellation', [RunCancellationController::class, 'store'])->name('runs.cancellation.store');
});

require __DIR__.'/settings.php';
