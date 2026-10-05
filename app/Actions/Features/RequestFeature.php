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
     * @param  array{deployment_id?: int, preview_id?: int, problem?: string, errors: list<array{class: string|null, message: string, count: int, place?: string|null, trace?: list<string>, during?: string|null}>}|null  $liveErrors  The errors the published app raised, or the app on show while the owner tried it, when the ask is to fix them
     * @param  array{deployment_id: int, checks: list<array{name: string, output: string}>}|null  $failedChecks  The checks that kept the app from going online, when the ask is to fix them
     * @param  list<array{path: string, name: string}>  $images  Pictures the owner attached, already kept
     * @param  array{of: int, tier: string, shortcuts: list<array{rule: string, path: string, line: int}>}|null  $tidy  The shortcuts to fix, when this is a background tidy-up
     */
    public function handle(Project $project, User $requester, string $prompt, ?array $selection = null, Experiment|null|false $experiment = false, ?array $liveErrors = null, ?array $failedChecks = null, array $images = [], ?array $tidy = null): FeatureRequest
    {
        $experiment = $experiment === false ? $project->experiment : $experiment;
        $branch = Experiment::branchOf($experiment) ?? Experiment::mainBranch();

        $featureRequest = $project->featureRequests()->create([
            'experiment_id' => $experiment?->id,
            'user_id' => $requester->id,
            'prompt' => $prompt,
            'selection' => $selection,
            'images' => $images === [] ? null : $images,
            'live_errors' => $liveErrors,
            'failed_checks' => $failedChecks,
            'tidy' => $tidy,
            'status' => FeatureRequestStatus::Generating,
            'generator' => $this->generators->getDefaultDriver(),
            'base_revision' => $this->repository->exists($project) ? $this->repository->head($project, $branch) : null,
        ]);

        $this->startRun->handle($featureRequest);

        return $featureRequest;
    }
}
