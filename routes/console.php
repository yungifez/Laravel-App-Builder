<?php

use App\Models\BoxCommand;
use App\Models\ModelGatewayGrant;
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
Schedule::command('ai:check-formats')->daily()->onOneServer()->when(fn () => (bool) config('builder.answer_formats.enabled'));
Schedule::command('model:prune', ['--model' => [BoxCommand::class, ModelGatewayGrant::class, WorkerHeartbeat::class]])->daily();
