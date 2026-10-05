<?php

namespace App\Jobs;

use App\Projects\ProjectRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Remove the files a deleted project leaves outside the database: its
 * code, the pictures the owner attached to changes, and the screenshots
 * the checks took. The project's rows are already gone, so the job holds
 * only what it needs to find the files.
 */
class ForgetProjectFiles implements ShouldQueue
{
    use Queueable;

    /**
     * The store can be out of reach for a while; each step is safe to run
     * again.
     */
    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 3600];

    /**
     * @param  list<string>  $shots  The screenshots' paths on the shots disk
     */
    public function __construct(public int $projectId, public array $shots = []) {}

    public function handle(ProjectRepository $repository): void
    {
        Storage::disk(Config::string('builder.construction.images.disk'))->deleteDirectory("request-images/{$this->projectId}");
        Storage::disk(Config::string('builder.verification.screens.shots_disk'))->delete($this->shots);

        $repository->forget($this->projectId);
    }
}
