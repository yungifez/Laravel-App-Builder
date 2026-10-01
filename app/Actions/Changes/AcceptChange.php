<?php

namespace App\Actions\Changes;

use App\Actions\Features\RetryFeatureRequest;
use App\Context\ProjectNotes;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\CodeShortcuts;
use App\Jobs\TriageShortcuts;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptChange
{
    public function __construct(
        private ProjectRepository $repository,
        private ProjectNotes $notes,
        private RetryFeatureRequest $retryFeatureRequest,
    ) {}

    /**
     * Commit a built, verified and reviewed change to the project's
     * repository. A follow-up is committed together with the changes it
     * builds on that are not accepted yet, as one commit. What the changes
     * did to the notes is saved to our database, never to the repository.
     *
     * Only the state that was checked is committed. When the app changed
     * after the change was checked, the same request (with the asks it
     * builds on that are not kept yet) is built and checked again on the
     * app as it is now, and that new request is returned.
     *
     * @throws ValidationException when the change cannot be accepted.
     */
    public function handle(FeatureRequest $featureRequest, User $owner): FeatureRequest
    {
        $run = $featureRequest->latestRun;

        if ($featureRequest->status !== FeatureRequestStatus::Generated || $run?->status !== RunStatus::Completed) {
            throw ValidationException::withMessages(['change' => __('Only a change whose run completed can be accepted.')]);
        }

        if ($featureRequest->commit_sha !== null) {
            throw ValidationException::withMessages(['change' => __('This change was accepted already.')]);
        }

        $project = $featureRequest->project;
        $branch = $featureRequest->branch();

        if ($branch === null) {
            throw ValidationException::withMessages(['change' => __('This idea was thrown away, so its changes cannot be kept.')]);
        }

        $this->repository->import($project);

        $lineage = $featureRequest->lineage();
        $pending = array_values(array_filter($lineage, fn (FeatureRequest $request) => $request->commit_sha === null));
        $accepted = array_values(array_filter($lineage, fn (FeatureRequest $request) => $request->commit_sha !== null));
        $base = $accepted !== [] ? (string) end($accepted)->commit_sha : ($featureRequest->base_revision ?? $this->repository->root($project));

        if ($this->repository->head($project, $branch) !== $base) {
            // Built again from the app as it is now. A change that builds
            // on others waiting to be kept is asked for with them, as one
            // request, so it does not rebuild on their old state.
            return $this->retryFeatureRequest->rebuild($featureRequest, $owner, array_slice($pending, 0, -1));
        }

        try {
            $sha = $this->repository->commitPatches(
                $project,
                $base,
                array_map(fn (FeatureRequest $request) => (string) $request->patch, $pending),
                $this->message($featureRequest, $pending),
                ['name' => $owner->name, 'email' => $owner->email],
                $branch,
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['change' => $exception->getMessage().' '.__('Ask for it again to build it on the current app.')]);
        }

        DB::transaction(function () use ($project, $branch, $pending, $sha, $run, $featureRequest) {
            foreach ($pending as $request) {
                $request->update(['commit_sha' => $sha, 'accepted_at' => now()]);
                $this->notes->apply($project, $branch, $request->note_changes ?? []);
            }

            $run->recordEvent('change_accepted', [
                'commit' => $sha,
                'requests' => array_map(fn (FeatureRequest $request) => $request->id, $pending),
                'feature_request_id' => $featureRequest->id,
            ]);
        });

        // Nothing waits on the answer, so it runs after the owner has moved on.
        foreach ($pending as $request) {
            if (config('builder.verification.shortcuts.triage.enabled') && CodeShortcuts::scans($request->patch)) {
                TriageShortcuts::dispatch($request)->delay(now()->addMinutes((int) config('builder.verification.shortcuts.triage.delay_minutes')));
            }
        }

        return $featureRequest->refresh();
    }

    /**
     * Name the commit as the app's own developer would. The repository can
     * belong to the customer, so the owner's words, and anything that
     * shows how the change was made, stay out of it.
     *
     * @param  list<FeatureRequest>  $requests
     */
    protected function message(FeatureRequest $featureRequest, array $requests): string
    {
        $subjects = array_filter(array_map(fn (FeatureRequest $request) => $request->latestRun?->plan['commit_subject'] ?? null, [...$requests, $featureRequest]));

        return (string) (end($subjects) ?: 'Update the application');
    }
}
