<?php

namespace App\Actions\Projects;

use App\Actions\Context\RequestNotesDraft;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateProject
{
    public function __construct(private ProjectRepository $repository, private RequestNotesDraft $requestNotesDraft) {}

    /**
     * Register a customer application for the owner and import its source
     * into the project's repository as the first commit. Notes are drafted
     * only when "draftNotes" asks: exploring costs the owner model tokens,
     * so by default they choose it on the app's "What I know" page.
     *
     * @throws ValidationException when the source cannot be imported.
     */
    public function handle(User $owner, string $name, string $sourcePath, bool $draftNotes = false): Project
    {
        return DB::transaction(function () use ($owner, $name, $sourcePath, $draftNotes) {
            $project = $owner->projects()->create([
                'name' => $name,
                'source_path' => $sourcePath,
            ]);

            try {
                $this->repository->import($project);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['source_path' => $exception->getMessage()]);
            }

            if ($draftNotes) {
                $this->requestNotesDraft->handle($project);
            }

            return $project;
        });
    }
}
