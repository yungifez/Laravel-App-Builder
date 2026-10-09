<?php

use App\Models\BoxCommand;
use App\Models\ModelGatewayGrant;
use App\Models\OAuthClient;
use App\Models\WorkerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('workspaces:reap')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('runs:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('previews:reap')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('runners:scale')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('publishing:collect-errors')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('publishing:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('ai:check-formats')->daily()->onOneServer()->when(fn () => (bool) config('builder.answer_formats.enabled'));
Schedule::command('model:prune', ['--model' => [BoxCommand::class, ModelGatewayGrant::class, OAuthClient::class, WorkerHeartbeat::class]])->daily();
// Lapsed and revoked OAuth passes go once they ended longer ago than a tool
// stays connected. Until then they still tie a live refresh token to its
// client, so the client is not pruned under it.
Schedule::command('passport:purge', ['--expired', '--hours' => (int) config('builder.agents.workers.project_days') * 24])->daily();
// Only the package lookups run unasked; the full checks stay on request.
Schedule::command('health:look-up-packages')->daily()->withoutOverlapping()->onOneServer()->when(fn () => (bool) config('builder.verification.security.enabled'));
