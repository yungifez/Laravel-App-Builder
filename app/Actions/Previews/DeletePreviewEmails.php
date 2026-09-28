<?php

namespace App\Actions\Previews;

use App\Models\Project;
use App\Previews\LoggedEmails;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class DeletePreviewEmails
{
    public function __construct(private ReadPreviewLog $readLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Delete emails from the list of emails the app on show sent. The app's
     * own log gets an entry that marks them, so nothing about them is kept
     * here, and they go when the app's log goes.
     *
     * @param  list<string>  $ids
     *
     * @throws ValidationException when the app is not running.
     */
    public function handle(Project $project, array $ids): void
    {
        $preview = $this->readLog->preview($project)
            ?? throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);

        // PHP runs in every Laravel app, and appends without a shell to quote for.
        $this->runPreviewCommand->handle(
            $preview,
            ['php', '-r', 'file_put_contents($argv[1], $argv[2], FILE_APPEND | LOCK_EX);', Config::string('builder.preview.log'), LoggedEmails::deletion($ids, now())],
            30,
            __('The emails could not be deleted. This is our fault. Try again.'),
        );

        Cache::forget("previews:{$preview->id}:log");
    }
}
