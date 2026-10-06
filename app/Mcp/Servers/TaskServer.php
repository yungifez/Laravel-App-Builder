<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CheckStatus;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\OpenPreview;
use App\Mcp\Tools\ReadFile;
use App\Mcp\Tools\SearchFiles;
use App\Mcp\Tools\ShareProgress;
use App\Mcp\Tools\SubmitChange;
use App\Mcp\Tools\TryChange;
use App\Mcp\Tools\WriteFile;
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
        ShareProgress::class,
        TryChange::class,
        SubmitChange::class,
        CheckStatus::class,
        OpenPreview::class,
        // For a tool with no folder of its own, as in a chat.
        ListFiles::class,
        SearchFiles::class,
        ReadFile::class,
        WriteFile::class,
    ];
}
