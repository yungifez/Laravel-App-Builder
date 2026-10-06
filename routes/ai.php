<?php

use App\Http\Controllers\WorkerCodeController;
use App\Http\Middleware\AuthenticateWorker;
use App\Mcp\Servers\TaskServer;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// A worker's tools for one change: our own coding agents, or the owner's
// own Claude Code or Codex. Its token opens this route and nothing else.
Mcp::web('/mcp/task', TaskServer::class)
    ->middleware([AuthenticateWorker::class, 'throttle:worker'])
    ->name('mcp.task');

// The same tools at one app's own address, for the owner's tool signed in
// through OAuth: a connector in the Claude app, VS Code or Cursor, which
// needs nothing installed and no token copied.
Mcp::oauthRoutes();

Mcp::web('/mcp/apps/{project}', TaskServer::class)
    ->middleware([AuthenticateWorker::class, 'throttle:worker'])
    ->name('mcp.app');

// The code a change starts from, for a tool that writes every change and
// may have no copy of the app. Only the signed address from get_task opens it.
Route::get('/worker-code/{run}', [WorkerCodeController::class, 'show'])
    ->middleware(['signed', 'throttle:worker', SubstituteBindings::class])
    ->name('worker-code.show');
