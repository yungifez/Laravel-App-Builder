<?php

namespace App\Actions\Changes;

use App\Models\FeatureRequest;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RevertChange
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Undo an accepted change with a new commit. Every request the commit
     * holds is marked as undone.
     *
     * @throws ValidationException when the change is not accepted or cannot be undone.
     */
    public function handle(FeatureRequest $featureRequest, User $owner): FeatureRequest
    {
        if (! $featureRequest->isAccepted()) {
            throw ValidationException::withMessages(['change' => __('Only an accepted change can be undone.')]);
        }

        $project = $featureRequest->project;
        $commit = (string) $featureRequest->commit_sha;

        try {
            $sha = $this->repository->revert(
                $project,
                $commit,
                Str::limit('Undo: '.Str::squish($featureRequest->prompt), 70)."\n\nThis reverts commit {$commit}.\n\nBuilder-Request: #{$featureRequest->id}",
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['change' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($project, $commit, $sha, $featureRequest) {
            $project->featureRequests()->where('commit_sha', $commit)->update(['revert_sha' => $sha, 'reverted_at' => now()]);

            $featureRequest->latestRun?->recordEvent('change_reverted', ['commit' => $commit, 'revert' => $sha]);
        });

        return $featureRequest->refresh();
    }
}
