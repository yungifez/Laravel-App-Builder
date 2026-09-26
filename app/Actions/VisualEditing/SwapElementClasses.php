<?php

namespace App\Actions\VisualEditing;

use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\TailwindClasses;
use App\VisualEditing\TemplateElement;
use Illuminate\Validation\ValidationException;

class SwapElementClasses
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Put back one side of a saved edit (undo or redo) with a new commit.
     *
     * The element must still have the other side's classes exactly. A model
     * or another person may have changed the element since; putting old
     * classes back then would silently throw their change away, so the
     * swap is refused instead.
     *
     * @throws ValidationException when the element is gone or its classes changed.
     */
    public function handle(VisualEdit $edit, string $from, string $to, string $message, User $owner): string
    {
        $project = $edit->project;
        $branch = $edit->branch();

        if ($branch === null) {
            throw ValidationException::withMessages(['edit' => __('This idea was thrown away, so its changes are gone.')]);
        }

        $head = $this->repository->head($project, $branch);
        $contents = $this->repository->show($project, $head, $edit->file);
        $element = $contents === null ? null : TemplateElement::at($contents, $edit->line, $edit->column);

        if ($element === null
            || $element->tag !== $edit->tag
            || TailwindClasses::normalize($element->classes['value'] ?? '') !== TailwindClasses::normalize($from)) {
            throw ValidationException::withMessages(['edit' => __('This part was changed since, so going back would lose that change.')]);
        }

        try {
            return $this->repository->commitFiles(
                $project,
                $head,
                [$edit->file => $element->withClasses($contents, $to)],
                $message,
                ['name' => $owner->name, 'email' => $owner->email],
                $branch,
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }
    }
}
