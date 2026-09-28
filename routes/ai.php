<?php

use App\Http\Middleware\AuthenticateWorker;
use App\Mcp\Servers\TaskServer;
use Laravel\Mcp\Facades\Mcp;

// A worker's tools for one change: our own coding agents, or the owner's
// own Claude Code or Codex. Its token opens this route and nothing else.
Mcp::web('/mcp/task', TaskServer::class)
    ->middleware([AuthenticateWorker::class, 'throttle:worker'])
    ->name('mcp.task');
