<?php

namespace App\Actions\Changes;

use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevertChange
{
    public function __construct(private ProjectRepository $repository, private ProjectNotes $notes) {}

    /**
     * Undo an accepted change with a new commit. Every request the commit
     * holds is marked as undone, and its notes go back where nobody
     * changed them since.
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
        $branch = $featureRequest->branch();

        if ($branch === null) {
            throw ValidationException::withMessages(['change' => __('This idea was thrown away already.')]);
        }

        try {
            $sha = $this->repository->revert(
                $project,
                $commit,
                $this->message($project, $commit),
                ['name' => $owner->name, 'email' => $owner->email],
                $branch,
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['change' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($project, $branch, $commit, $sha, $featureRequest) {
            $requests = $project->featureRequests()->where('commit_sha', $commit)->orderByDesc('id')->get();

            foreach ($requests as $request) {
                $request->update(['revert_sha' => $sha, 'reverted_at' => now()]);
                $this->notes->undo($project, $branch, $request->note_changes ?? []);
            }

            $featureRequest->latestRun?->recordEvent('change_reverted', ['commit' => $commit, 'revert' => $sha]);
        });

        return $featureRequest->refresh();
    }

    /**
     * Word the commit as `git revert` does, so it reads like any other.
     */
    protected function message(Project $project, string $commit): string
    {
        $subject = trim($this->repository->git($project, ['log', '-1', '--format=%s', $commit])->output());

        return "Revert \"{$subject}\"\n\nThis reverts commit {$commit}.";
    }
}
