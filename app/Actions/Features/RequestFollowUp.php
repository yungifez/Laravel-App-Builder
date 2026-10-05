<?php

namespace App\Actions\Features;

use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Models\Experiment;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

class RequestFollowUp
{
    public function __construct(
        private StartRun $startRun,
        private ProjectRepository $repository,
    ) {}

    /**
     * Determine if a message can follow on in the change's chat: after a
     * built change that was not undone, or after an answer.
     */
    public static function continuable(FeatureRequest $parent): bool
    {
        return ($parent->status === FeatureRequestStatus::Generated && $parent->reverted_at === null)
            || $parent->status === FeatureRequestStatus::Answered;
    }

    /**
     * Ask for more in a change's chat, as a follow-up request. It builds on
     * top of the latest change in the chat that is not kept yet, or on the
     * project's latest commit. Answers change nothing, so they are passed
     * over.
     *
     * @param  array{file: string, line: int, column: int, tag: string, text: string|null, area: string|null, behavior?: string|null}|null  $selection  The element the owner pointed at
     * @param  list<array{path: string, name: string}>  $images  Pictures the owner attached, already kept
     * @param  array<string, mixed>|null  $liveErrors  A problem the change's copy ran into, for a fix
     *
     * @throws ValidationException when there is nothing to build on.
     */
    public function handle(FeatureRequest $parent, User $requester, string $prompt, ?string $stepKey = null, ?array $selection = null, array $images = [], ?array $liveErrors = null): FeatureRequest
    {
        if (! self::continuable($parent)) {
            throw ValidationException::withMessages([
                'prompt' => __('This change cannot be built on. Ask for it as a new change.'),
            ]);
        }

        $built = $parent;

        while ($built !== null && $built->patch === null) {
            $built = $built->parent;
        }

        $followUp = $parent->followUps()->create([
            'user_id' => $requester->id,
            'project_id' => $parent->project_id,
            'experiment_id' => $parent->experiment_id,
            'prompt' => $prompt,
            'selection' => $selection,
            'images' => $images === [] ? null : $images,
            'target_step' => $stepKey,
            'live_errors' => $liveErrors,
            'status' => FeatureRequestStatus::Generating,
            'generator' => $parent->generator,
            'base_revision' => $built !== null && $built->commit_sha === null ? $built->base_revision : $this->latest($parent),
        ]);

        $this->startRun->handle($followUp);

        return $followUp;
    }

    /**
     * Get the project's latest commit on the parent's line of work, if the
     * project has a history yet.
     */
    protected function latest(FeatureRequest $parent): ?string
    {
        return $this->repository->exists($parent->project)
            ? $this->repository->head($parent->project, $parent->branch() ?? Experiment::mainBranch())
            : null;
    }
}
