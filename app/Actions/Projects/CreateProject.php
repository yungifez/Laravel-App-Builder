<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateProject
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Register a customer application for the owner and import its source
     * into the project's repository as the first commit.
     *
     * @throws ValidationException when the source cannot be imported.
     */
    public function handle(User $owner, string $name, string $sourcePath): Project
    {
        return DB::transaction(function () use ($owner, $name, $sourcePath) {
            $project = $owner->projects()->create([
                'name' => $name,
                'source_path' => $sourcePath,
            ]);

            try {
                $this->repository->import($project);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['source_path' => $exception->getMessage()]);
            }

            return $project;
        });
    }
}
