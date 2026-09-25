<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\FeatureRequestStepChangeController;
use App\Http\Controllers\ProjectController;
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
});

require __DIR__.'/settings.php';
