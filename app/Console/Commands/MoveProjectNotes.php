<?php

namespace App\Console\Commands;

use App\Actions\Context\ImportLegacyNotes;
use App\Enums\ExperimentStatus;
use App\Models\Experiment;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('projects:move-notes')]
#[Description('Move notes that older versions kept inside app repositories into the database')]
class MoveProjectNotes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImportLegacyNotes $importLegacyNotes, ProjectRepository $repository): int
    {
        $failed = false;

        Project::query()->with('experiments')->each(function (Project $project) use ($importLegacyNotes, $repository, &$failed) {
            if (! $repository->exists($project)) {
                return;
            }

            $branches = [
                Experiment::mainBranch(),
                ...$project->experiments->where('status', ExperimentStatus::Open)->pluck('branch')->all(),
            ];

            foreach ($branches as $branch) {
                try {
                    $moved = $importLegacyNotes->fromBranch($project, $branch);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->components->error("{$project->name} ({$branch}): {$exception->getMessage()}");
                    $failed = true;

                    continue;
                }

                if ($moved > 0) {
                    $this->components->info("{$project->name} ({$branch}): moved {$moved} files.");
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
