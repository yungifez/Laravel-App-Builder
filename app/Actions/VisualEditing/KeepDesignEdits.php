<?php

namespace App\Actions\VisualEditing;

use App\Actions\Features\RequestVerification;
use App\Models\Project;
use App\Models\Verification;
use App\Projects\Exceptions\RepositoryConflict;
use App\VisualEditing\DesignDrafts;
use Illuminate\Validation\ValidationException;

class KeepDesignEdits
{
    public function __construct(
        private DesignDrafts $designDrafts,
        private RequestVerification $requestVerification,
    ) {}

    /**
     * Check the design edits that wait on the app. They join the app when
     * the checks pass (CommitDesignEdits); until then, they wait as they
     * are and the owner cannot change them.
     *
     * @throws ValidationException when there is nothing to keep or the edits no longer fit.
     */
    public function handle(Project $project): Verification
    {
        $draft = $this->designDrafts->find($project);

        if ($draft === null || trim((string) $draft->patch) === '') {
            throw ValidationException::withMessages(['keep' => __('There are no edits to keep.')]);
        }

        if ($this->designDrafts->checking($draft)) {
            throw ValidationException::withMessages(['keep' => __('Your edits are being checked already.')]);
        }

        try {
            $this->designDrafts->catchUp($draft);
        } catch (RepositoryConflict) {
            throw ValidationException::withMessages(['keep' => __('Your app changed in the same places as your edits. Undo your edits and make them again.')]);
        }

        return $this->requestVerification->handle($draft->refresh());
    }
}
