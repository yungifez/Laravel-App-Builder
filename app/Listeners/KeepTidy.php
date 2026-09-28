<?php

namespace App\Listeners;

use App\Actions\Changes\AcceptChange;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Events\RunStatusChanged;
use App\Features\CodeShortcuts;
use App\Jobs\TidyShortcuts;
use App\Models\Experiment;
use App\Models\FeatureRequest;
use App\Projects\ProjectRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Validation\ValidationException;

class KeepTidy implements ShouldQueue
{
    public function __construct(
        private AcceptChange $acceptChange,
        private ProjectRepository $repository,
    ) {}

    /**
     * Keep a background tidy-up that passed the checks and dealt with every
     * shortcut, in the owner's name, as they would. One the light model
     * could not finish goes to the usual model; one the usual model could
     * not finish is put aside, and the app is left as it was.
     */
    public function handle(RunStatusChanged $event): void
    {
        $request = $event->run->featureRequest?->fresh();
        $tidy = $request?->tidy;

        if ($request === null || $tidy === null || $request->commit_sha !== null || ! in_array($event->to, [RunStatus::Completed, RunStatus::NeedsUserDecision, RunStatus::Failed], true)) {
            return;
        }

        $of = FeatureRequest::query()->find($tidy['of']);

        if ($of === null || $of->reverted_at !== null) {
            $this->putAside($request, 'undone');

            return;
        }

        if ($event->to !== RunStatus::Completed || $request->status !== FeatureRequestStatus::Generated) {
            $this->tryAgain($request, $of, 'not_finished');

            return;
        }

        $left = array_values(array_filter($tidy['shortcuts'], fn (array $shortcut) => ! CodeShortcuts::addressed(
            $shortcut['path'],
            (string) CodeShortcuts::code($shortcut, $of->patch),
            $request->patch,
        )));

        if ($left !== []) {
            $this->tryAgain($request, $of, 'shortcuts_left');

            return;
        }

        // Built on an app the owner has changed since: built again on the
        // app as it is now, by the same model.
        if ($this->repository->head($request->project, Experiment::mainBranch()) !== $request->base_revision) {
            $this->putAside($request, 'app_changed');
            TidyShortcuts::dispatch($of, $tidy['shortcuts'], $tidy['tier']);

            return;
        }

        $owner = $request->project->owner;

        if ($owner === null) {
            $this->putAside($request, 'no_owner');

            return;
        }

        try {
            $this->acceptChange->handle($request, $owner);
        } catch (ValidationException $exception) {
            $this->putAside($request, 'not_kept', ['message' => $exception->getMessage()]);
        }
    }

    /**
     * Hand what the light model could not finish to the usual model, or put
     * the tidy-up aside when the usual model could not finish it either.
     */
    protected function tryAgain(FeatureRequest $request, FeatureRequest $of, string $reason): void
    {
        $this->putAside($request, $reason);

        if (($request->tidy['tier'] ?? null) === 'light') {
            TidyShortcuts::dispatch($of, $request->tidy['shortcuts'], 'full');
        }
    }

    /**
     * Take the tidy-up off the owner's list, with the reason on its run.
     *
     * @param  array<string, mixed>  $details
     */
    protected function putAside(FeatureRequest $request, string $reason, array $details = []): void
    {
        $request->update(['dismissed_at' => now()]);
        $request->latestRun?->recordEvent('tidy_put_aside', ['reason' => $reason, 'tier' => $request->tidy['tier'] ?? null, ...$details]);
    }
}
