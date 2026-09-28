<?php

namespace App\Actions\Previews;

use App\Models\Project;
use App\Previews\LoggedProblems;

class ReadPreviewProblems
{
    public function __construct(private ReadPreviewLog $readLog) {}

    /**
     * Get the problems the app on show ran into while the owner tried it,
     * the most recent first.
     *
     * @return list<array{id: string, words: string, class: string|null, message: string, place: string|null, trace: list<string>, count: int, first_at: string|null, last_at: string|null}>
     */
    public function handle(Project $project): array
    {
        $preview = $this->readLog->preview($project);

        return $preview === null ? [] : LoggedProblems::in($this->readLog->handle($preview));
    }
}
