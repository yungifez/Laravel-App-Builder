<?php

namespace App\Actions\Features;

use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Features\FeatureGeneratorManager;
use App\Models\Experiment;
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
     * Record the owner's feature request, based on the latest commit of the
     * idea it is for (or the main app), and start a run to build it.
     *
     * @param  array{file: string, line: int, column: int, tag: string, text: string|null, area: string|null}|null  $selection  The element the owner pointed at
     * @param  Experiment|null|false  $experiment  The idea, null for the main app, or false for the one the owner is working in
     * @param  array{deployment_id: int, errors: list<array{class: string|null, message: string, count: int}>}|null  $liveErrors  The published app's errors, when the ask is to fix them
     */
    public function handle(Project $project, User $requester, string $prompt, ?array $selection = null, Experiment|null|false $experiment = false, ?array $liveErrors = null): FeatureRequest
    {
        $experiment = $experiment === false ? $project->experiment : $experiment;
        $branch = Experiment::branchOf($experiment) ?? Experiment::mainBranch();

        $featureRequest = $project->featureRequests()->create([
            'experiment_id' => $experiment?->id,
            'user_id' => $requester->id,
            'prompt' => $prompt,
            'selection' => $selection,
            'live_errors' => $liveErrors,
            'status' => FeatureRequestStatus::Generating,
            'generator' => $this->generators->getDefaultDriver(),
            'base_revision' => $this->repository->exists($project) ? $this->repository->head($project, $branch) : null,
        ]);

        $this->startRun->handle($featureRequest);

        return $featureRequest;
    }
}
