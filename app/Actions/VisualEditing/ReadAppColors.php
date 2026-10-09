<?php

namespace App\Actions\VisualEditing;

use App\Models\Project;
use App\Projects\ProjectRepository;
use App\VisualEditing\ThemeColors;
use Illuminate\Support\Facades\Cache;

class ReadAppColors
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * The colours the app's stylesheets write at its latest version, so the
     * design panel offers the app's own colours, whatever its design system
     * calls them. A version never changes, so its colours are kept.
     *
     * @return list<array{name: string, variable: string, classes: bool}>
     */
    public function handle(Project $project): array
    {
        if (! $this->repository->exists($project)) {
            return [];
        }

        $head = $this->repository->head($project);

        return Cache::remember("projects:{$project->id}:app-colors:{$head}", now()->addDay(), fn () => ThemeColors::discover($this->repository->stylesheets($project, $head)));
    }

    /**
     * The names of the app's colours that its classes can use, such as
     * "primary" in `bg-primary`.
     *
     * @return list<string>
     */
    public function names(Project $project): array
    {
        return array_column(array_filter($this->handle($project), fn (array $color) => $color['classes']), 'name');
    }
}
