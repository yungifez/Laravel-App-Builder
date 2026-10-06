<?php

namespace App\Actions\Changes;

use App\Actions\Features\AcceptFindings;
use App\Actions\Features\RetryFeatureRequest;
use App\Actions\Runs\TransitionRun;
use App\Context\ProjectNotes;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\VerificationStatus;
use App\Features\AppDrift;
use App\Features\CodeShortcuts;
use App\Features\NewMessages;
use App\Jobs\TriageShortcuts;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptChange
{
    public function __construct(
        private ProjectRepository $repository,
        private ProjectNotes $notes,
        private RetryFeatureRequest $retryFeatureRequest,
        private AcceptFindings $acceptFindings,
        private TransitionRun $transitionRun,
    ) {}

    /**
     * Whether the owner may keep a change our review would not pass, as
     * their own decision. Only the review doubted it: the checks did not
     * fail, so it applies and runs. A change whose checks failed, or that
     * must be made again, is never kept this way.
     */
    public static function keepableDespiteReview(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        return $featureRequest->status === FeatureRequestStatus::Generated
            && $featureRequest->commit_sha === null
            && $run?->status === RunStatus::NeedsUserDecision
            && $run->stop_reason === StopReason::ReviewFindings
            && in_array($featureRequest->verifications()->latest('id')->first()?->status, [VerificationStatus::Passed, VerificationStatus::Unverified], true)
            && ! RetryFeatureRequest::mustBeMadeAgain($featureRequest);
    }

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
    public function handle(FeatureRequest $featureRequest, User $owner, bool $despiteReview = false): FeatureRequest
    {
        $run = $featureRequest->latestRun;
        $anyway = $despiteReview && self::keepableDespiteReview($featureRequest);

        if ($featureRequest->status !== FeatureRequestStatus::Generated || ($run?->status !== RunStatus::Completed && ! $anyway)) {
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

        // A new email or text message reaches real people once the app is
        // published, so the owner approves it first (§12), in each change
        // this one keeps.
        foreach ($pending as $request) {
            $messages = $request->verifications()->latest('id')->first()?->evidence['messages'] ?? null;

            if (NewMessages::findings($messages, $this->acceptFindings->identities($request)) !== []) {
                throw ValidationException::withMessages(['change' => __('This change sends something new to people. Say you want it in how we know the change works, then keep it.')]);
            }

            // Its own files stopped the checks, or it no longer fits the
            // app: only making it again helps, never keeping it.
            if (RetryFeatureRequest::mustBeMadeAgain($request)) {
                throw ValidationException::withMessages(['change' => __('This change cannot be kept as it is. Try again to make it afresh.')]);
            }
        }
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

        DB::transaction(function () use ($project, $branch, $pending, $sha, $run, $featureRequest, $anyway) {
            foreach ($pending as $request) {
                $request->update(['commit_sha' => $sha, 'accepted_at' => now()]);
                $this->notes->apply($project, $branch, $request->note_changes ?? []);
            }

            $run->recordEvent('change_accepted', [
                'commit' => $sha,
                'requests' => array_map(fn (FeatureRequest $request) => $request->id, $pending),
                'feature_request_id' => $featureRequest->id,
                // The owner's own decision, with what our review doubted.
                'despite_review' => $anyway ? ($run->feedback['details'] ?? []) : null,
            ]);

            // The owner stood in for the review, and passed the change.
            if ($anyway) {
                $this->transitionRun->handle($run, RunStatus::Reviewing, details: ['reason' => 'kept_despite_review']);
                $this->transitionRun->handle($run, RunStatus::Completed, details: ['reason' => 'kept_despite_review']);
            }
        });

        $this->ratchetDrift($featureRequest);

        // Nothing waits on the answer, so it runs after the owner has moved on.
        foreach ($pending as $request) {
            if (config('builder.verification.shortcuts.triage.enabled') && CodeShortcuts::scans($request->patch)) {
                TriageShortcuts::dispatch($request)->delay(now()->addMinutes((int) config('builder.verification.shortcuts.triage.delay_minutes')));
            }
        }

        return $featureRequest->refresh();
    }

    /**
     * Set each area's ceiling of work per request from the checks of the
     * change just kept (direction 33, a drift measure). It moves down when
     * an area does less, and up only where the owner said they want the
     * growth.
     */
    protected function ratchetDrift(FeatureRequest $featureRequest): void
    {
        $measured = $featureRequest->verifications()->latest('id')->first()?->evidence['drift']['areas'] ?? null;

        if (! config('builder.verification.drift.enabled') || $measured === null) {
            return;
        }

        $wanted = $featureRequest->acceptedFindings()->where('kind', AppDrift::GREW)->pluck('identity')
            ->map(fn (mixed $identity) => Str::after((string) $identity, AppDrift::GREW.'|'))->all();
        $project = $featureRequest->project;

        $project->forceFill(['drift_ceilings' => AppDrift::ratchet($measured, $project->drift_ceilings ?? [], config()->float('builder.verification.drift.slack'), array_values($wanted))])->save();
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
