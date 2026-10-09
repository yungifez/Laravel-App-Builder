<?php

namespace App\Actions\VisualEditing;

use App\Models\Project;
use App\Projects\ProjectRepository;
use App\VisualEditing\TailwindTheme;
use Illuminate\Support\Facades\Cache;

class ReadAppTheme
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * The names the app's Tailwind theme gives each scale at its latest
     * version, such as "hero" for `text-hero`, so a design change replaces
     * the classes Tailwind would, whatever the app has named. A version
     * never changes, so its names are kept.
     *
     * @return array<string, list<string>>
     */
    public function handle(Project $project): array
    {
        if (! $this->repository->exists($project)) {
            return [];
        }

        $head = $this->repository->head($project);

        return Cache::remember("projects:{$project->id}:app-theme:{$head}", now()->addDay(), fn () => TailwindTheme::discover($this->repository->stylesheets($project, $head)));
    }
}
