<?php

namespace App\Actions\Features;

use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Features\FeatureGeneratorManager;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;

class RequestFeature
{
    public function __construct(
        private FeatureGeneratorManager $generators,
        private StartRun $startRun,
        private ProjectRepository $repository,
    ) {}

    /**
     * Record the owner's feature request, based on the project's latest
     * commit, and start a run to build it.
     *
     * @param  array{file: string, line: int, column: int, tag: string, text: string|null, area: string|null}|null  $selection  The element the owner pointed at
     */
    public function handle(Project $project, User $requester, string $prompt, ?array $selection = null): FeatureRequest
    {
        $featureRequest = $project->featureRequests()->create([
            'user_id' => $requester->id,
            'prompt' => $prompt,
            'selection' => $selection,
            'status' => FeatureRequestStatus::Generating,
            'generator' => $this->generators->getDefaultDriver(),
            'base_revision' => $this->repository->exists($project) ? $this->repository->head($project) : null,
        ]);

        $this->startRun->handle($featureRequest);

        return $featureRequest;
    }
}
