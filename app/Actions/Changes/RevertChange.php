<?php

namespace App\Actions\Changes;

use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RevertChange
{
    public function __construct(private ProjectRepository $repository, private ProjectNotes $notes) {}

    /**
     * Undo an accepted change with a new commit. Every request the commit
     * holds is marked as undone, and its notes go back where nobody
     * changed them since.
     *
     * Only the newest kept change is undone: a later change may rely on it
     * in files git cannot see conflict, and undoing it would break the app
     * without a word. The row is locked, so two quick clicks undo it once.
     *
     * @throws ValidationException when the change is not accepted or cannot be undone.
     */
    public function handle(FeatureRequest $featureRequest, User $owner): FeatureRequest
    {
        return DB::transaction(function () use ($featureRequest, $owner) {
            $featureRequest = FeatureRequest::query()->lockForUpdate()->findOrFail($featureRequest->id);

            if ($featureRequest->reverted_at !== null) {
                throw ValidationException::withMessages(['change' => __('You undid this change already.')]);
            }

            if (! $featureRequest->isAccepted()) {
                throw ValidationException::withMessages(['change' => __('Only an accepted change can be undone.')]);
            }

            $project = $featureRequest->project;
            $commit = (string) $featureRequest->commit_sha;
            $branch = $featureRequest->branch();

            if ($branch === null) {
                throw ValidationException::withMessages(['change' => __('This idea was thrown away already.')]);
            }

            $later = $this->later($featureRequest, $commit);

            if ($later->isNotEmpty()) {
                throw ValidationException::withMessages(['change' => $this->nextStep($later)]);
            }

            try {
                $sha = $this->repository->revert(
                    $project,
                    $commit,
                    $this->message($project, $commit),
                    ['name' => $owner->name, 'email' => $owner->email],
                    $branch,
                );
            } catch (RepositoryConflict) {
                throw ValidationException::withMessages(['change' => $this->nextStep($later)]);
            }

            $requests = $project->featureRequests()->where('commit_sha', $commit)->orderByDesc('id')->get();

            foreach ($requests as $request) {
                $request->update(['revert_sha' => $sha, 'reverted_at' => now()]);
                $this->notes->undo($project, $branch, $request->note_changes ?? []);

                // The owner's tool hears nothing more about a change undone.
                $request->runs()->each(fn (Run $run) => $run->tokens()->delete());
            }

            $featureRequest->latestRun?->recordEvent('change_reverted', ['commit' => $commit, 'revert' => $sha]);

            return $featureRequest->refresh();
        });
    }

    /**
     * Get the changes kept after this one on its line that are not undone,
     * newest first. Each one may rely on this change.
     *
     * @return Collection<int, FeatureRequest>
     */
    protected function later(FeatureRequest $featureRequest, string $commit): Collection
    {
        return $featureRequest->project->featureRequests()
            ->where('experiment_id', $featureRequest->experiment_id)
            ->whereNotNull('commit_sha')
            ->whereNull('reverted_at')
            ->where('commit_sha', '!=', $commit)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (FeatureRequest $request) => $this->repository->isAncestor($featureRequest->project, $commit, (string) $request->commit_sha))
            ->unique('commit_sha')
            ->values();
    }

    /**
     * Say why the change cannot be undone by itself, and which kept
     * changes to undo first, so the owner knows without reading code.
     *
     * @param  Collection<int, FeatureRequest>  $later
     */
    protected function nextStep(Collection $later): string
    {
        if ($later->isEmpty()) {
            return __('Changes you kept after this one build on it, so it cannot be undone by itself. Undo those first, newest first, then undo this one.');
        }

        return trans_choice(
            'A later change may rely on this one, so it cannot be undone by itself. Undo :changes first, then undo this one.|Later changes may rely on this one, so it cannot be undone by itself. Undo these first, newest first: :changes. Then undo this one.',
            $later->count(),
            ['changes' => $later->map(fn (FeatureRequest $request) => '“'.Str::limit($request->background() ?? $request->prompt, 60).'”')->implode(', ')],
        );
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
