<?php

use App\Http\Controllers\BoxCommandController;
use App\Http\Controllers\RunnerController;
use App\Http\Middleware\AuthenticateRunner;
use Illuminate\Support\Facades\Route;

// Box runners connect out to these routes with their token; the box itself
// accepts no connections.
Route::middleware(AuthenticateRunner::class)->prefix('runner')->name('runner.')->group(function () {
    Route::post('hello', [RunnerController::class, 'hello'])->name('hello');
    Route::post('socket-auth', [RunnerController::class, 'socketAuth'])->name('socket-auth');
    Route::post('commands/claim', [BoxCommandController::class, 'claim'])->name('commands.claim');
    Route::post('commands/{command}/result', [BoxCommandController::class, 'result'])->name('commands.result');
    Route::get('commands/{command}/archive', [BoxCommandController::class, 'archive'])->name('commands.archive');
});
