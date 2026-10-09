<?php

namespace App\Actions\VisualEditing;

use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\DesignDrafts;
use Illuminate\Validation\ValidationException;

class SwapMovedElement
{
    public function __construct(
        private ProjectRepository $repository,
        private DesignDrafts $designDrafts,
    ) {}

    /**
     * Put back one side of a saved move (undo or redo) with a new commit:
     * the file as it was before the move, or as the move left it.
     *
     * The file must still be exactly the other side. Anything else changed
     * in it since would be thrown away by putting the old file back, so the
     * swap is refused instead; undoing the later changes first makes it
     * possible again.
     *
     * @throws ValidationException when the file changed since.
     */
    public function handle(VisualEdit $edit, string $fromRevision, string $toRevision, string $message, User $owner): string
    {
        $project = $edit->project;
        $branch = $edit->branch();

        if ($branch === null) {
            throw ValidationException::withMessages(['edit' => $edit->feature_request_id === null
                ? __('This idea was thrown away, so its changes are gone.')
                : __('These edits are not waiting any more, so they cannot be undone here.')]);
        }

        $this->designDrafts->refuseWhileChecking($edit->featureRequest);

        $head = $this->repository->head($project, $branch);
        $to = $this->repository->show($project, $toRevision, $edit->file);

        if ($to === null || $this->repository->show($project, $head, $edit->file) !== $this->repository->show($project, $fromRevision, $edit->file)) {
            throw ValidationException::withMessages(['edit' => __('This page was changed since, so going back would lose that change.')]);
        }

        try {
            return $this->repository->commitFiles($project, $head, [$edit->file => $to], $message, ['name' => $owner->name, 'email' => $owner->email], $branch);
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }
    }
}
