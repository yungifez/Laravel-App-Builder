<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CheckStatus;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\SubmitChange;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The tools a worker gets for one change. Whoever runs the worker can read
 * every name, description and answer here, so they say what to do, never
 * how we decided it (architecture §11, "Workers").
 */
#[Name('Task')]
#[Version('0.1.0')]
#[Instructions('Tools for one change to this app. Start with get_task: it says what to build, what must keep working and how the change is checked.')]
class TaskServer extends Server
{
    protected array $tools = [
        GetTask::class,
        SubmitChange::class,
        CheckStatus::class,
    ];
}
