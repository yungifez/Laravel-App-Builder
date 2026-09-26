<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Project;
use App\Projects\ProjectRepository;

class DescribeProjectPreview
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Describe the project's running copy for a page that shows it: its
     * state, where it is served, and whether a newer kept change is still
     * being put in place.
     *
     * @return array{id: int, status: string, error: string|null, origin: string, revision: string|null, updating: bool}|null
     */
    public function handle(Project $project): ?array
    {
        $preview = $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        if ($preview === null) {
            return null;
        }

        return [
            'id' => $preview->id,
            'status' => $preview->status->value,
            'error' => $preview->error,
            'origin' => rtrim($preview->url(), '/'),
            'revision' => $preview->revision,
            'updating' => $preview->status === PreviewStatus::Ready && $this->repository->exists($project) && $preview->revision !== $this->repository->head($project),
        ];
    }
}
