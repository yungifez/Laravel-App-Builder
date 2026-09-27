<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\ExperimentController;
use App\Http\Controllers\ExperimentMergeController;
use App\Http\Controllers\FeatureRequestAcceptanceController;
use App\Http\Controllers\FeatureRequestAnswerController;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\FeatureRequestDismissalController;
use App\Http\Controllers\FeatureRequestPreviewController;
use App\Http\Controllers\FeatureRequestRetryController;
use App\Http\Controllers\FeatureRequestReversionController;
use App\Http\Controllers\FeatureRequestStepChangeController;
use App\Http\Controllers\FeatureRequestVerificationController;
use App\Http\Controllers\NewProjectController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationReadController;
use App\Http\Controllers\Operations\AttentionController;
use App\Http\Controllers\Operations\ChangeController as OperationsChangeController;
use App\Http\Controllers\PageConsistencyController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectEditorController;
use App\Http\Controllers\ProjectExperimentController;
use App\Http\Controllers\ProjectNotesDraftController;
use App\Http\Controllers\ProjectPreviewController;
use App\Http\Controllers\ProjectPublishingController;
use App\Http\Controllers\ProjectUnderstandingController;
use App\Http\Controllers\RunCancellationController;
use App\Http\Controllers\VisualEditController;
use App\Http\Controllers\VisualEditReversionController;
use App\Http\Controllers\VisualMoveController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::post('projects/new', [NewProjectController::class, 'store'])->name('projects.new.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('projects/{project}/understanding', [ProjectUnderstandingController::class, 'show'])->name('projects.understanding.show');
    Route::put('projects/{project}/understanding', [ProjectUnderstandingController::class, 'update'])->name('projects.understanding.update');
    Route::post('projects/{project}/notes-draft', [ProjectNotesDraftController::class, 'store'])->name('projects.notes-draft.store');
    Route::delete('projects/{project}/notes-draft', [ProjectNotesDraftController::class, 'destroy'])->name('projects.notes-draft.destroy');
    Route::put('projects/{project}/publishing', [ProjectPublishingController::class, 'update'])->name('projects.publishing.update');
    Route::post('projects/{project}/deployments', [DeploymentController::class, 'store'])->name('deployments.store');
    Route::get('projects/{project}/editor', [ProjectEditorController::class, 'show'])->name('projects.editor.show');
    Route::post('projects/{project}/previews', [ProjectPreviewController::class, 'store'])->name('projects.previews.store');
    Route::post('projects/{project}/experiments', [ExperimentController::class, 'store'])->name('experiments.store');
    Route::put('projects/{project}/experiment', [ProjectExperimentController::class, 'update'])->name('projects.experiment.update');
    Route::post('experiments/{experiment}/merge', [ExperimentMergeController::class, 'store'])->name('experiments.merge.store');
    Route::delete('experiments/{experiment}', [ExperimentController::class, 'destroy'])->name('experiments.destroy');
    Route::post('projects/{project}/visual-edits', [VisualEditController::class, 'store'])->name('visual-edits.store');
    Route::post('projects/{project}/visual-moves', [VisualMoveController::class, 'store'])->name('visual-moves.store');
    Route::post('projects/{project}/page-consistency', [PageConsistencyController::class, 'store'])->name('page-consistency.store');
    Route::post('visual-edits/{visualEdit}/reversion', [VisualEditReversionController::class, 'store'])->name('visual-edits.reversion.store');
    Route::delete('visual-edits/{visualEdit}/reversion', [VisualEditReversionController::class, 'destroy'])->name('visual-edits.reversion.destroy');

    Route::post('projects/{project}/feature-requests', [FeatureRequestController::class, 'store'])->name('feature-requests.store');
    Route::get('feature-requests/{featureRequest}', [FeatureRequestController::class, 'show'])->name('feature-requests.show');
    Route::post('feature-requests/{featureRequest}/step-changes', [FeatureRequestStepChangeController::class, 'store'])->name('feature-requests.step-changes.store');
    Route::post('feature-requests/{featureRequest}/verifications', [FeatureRequestVerificationController::class, 'store'])->name('feature-requests.verifications.store');
    Route::post('feature-requests/{featureRequest}/acceptance', [FeatureRequestAcceptanceController::class, 'store'])->name('feature-requests.acceptance.store');
    Route::post('feature-requests/{featureRequest}/answers', [FeatureRequestAnswerController::class, 'store'])->name('feature-requests.answers.store');
    Route::post('feature-requests/{featureRequest}/retries', [FeatureRequestRetryController::class, 'store'])->name('feature-requests.retries.store');
    Route::post('feature-requests/{featureRequest}/reversion', [FeatureRequestReversionController::class, 'store'])->name('feature-requests.reversion.store');
    Route::post('feature-requests/{featureRequest}/dismissal', [FeatureRequestDismissalController::class, 'store'])->name('feature-requests.dismissal.store');
    Route::delete('feature-requests/{featureRequest}/dismissal', [FeatureRequestDismissalController::class, 'destroy'])->name('feature-requests.dismissal.destroy');
    Route::post('feature-requests/{featureRequest}/previews', [FeatureRequestPreviewController::class, 'store'])->name('feature-requests.previews.store');
    Route::get('previews/{preview}', [PreviewController::class, 'show'])->name('previews.show');
    Route::delete('previews/{preview}', [PreviewController::class, 'destroy'])->name('previews.destroy');
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('notifications/read', NotificationReadController::class)->name('notifications.read');
    Route::post('runs/{run}/cancellation', [RunCancellationController::class, 'store'])->name('runs.cancellation.store');
});

// For operators only: every owner's changes, and what needs attention.
Route::middleware(['auth', 'verified', 'can:viewOperations'])->prefix('operations')->name('operations.')->group(function () {
    Route::get('/', AttentionController::class)->name('attention');
    Route::get('changes', [OperationsChangeController::class, 'index'])->name('changes.index');
    Route::get('changes/{featureRequest}', [OperationsChangeController::class, 'show'])->name('changes.show');
});

require __DIR__.'/settings.php';
