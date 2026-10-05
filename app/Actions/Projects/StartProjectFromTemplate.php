<?php

namespace App\Actions\Projects;

use App\Actions\Context\UpdateProjectNotes;
use App\Actions\Features\RequestFeature;
use App\Actions\Features\StoreRequestImages;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Models\User;
use App\Projects\DesignDirection;
use App\Projects\ProjectRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StartProjectFromTemplate
{
    public function __construct(
        private CreateProject $createProject,
        private UpdateProjectNotes $updateProjectNotes,
        private ProjectNotes $notes,
        private ApplyDesignDirection $applyDesignDirection,
        private RequestFeature $requestFeature,
        private ProjectRepository $repository,
        private StoreRequestImages $storeRequestImages,
    ) {}

    /**
     * Get the folder new apps start from, or null when there is none.
     */
    public static function template(): ?string
    {
        $template = config('builder.projects.template');

        return is_string($template) && $template !== '' && is_dir($template) ? $template : null;
    }

    /**
     * Start a new app from the configured template and write the owner's
     * one answer, what the app is for, into its notes. The look the owner
     * picked, if any, sets the app's theme and design contract. That same
     * sentence is then the app's first change, so the owner's first sight
     * is their app, built, checked and waiting for them to keep it.
     *
     * @param  UploadedFile|array<int, UploadedFile>|null  $images  Sketches or screenshots of what the owner has in mind, for the first version
     * @param  list<string>  $includes  What the first version includes, from a starter the owner kept
     *
     * @throws ValidationException when no template is configured or it
     *                             cannot be imported.
     */
    public function handle(User $owner, string $name, string $purpose, ?DesignDirection $design = null, UploadedFile|array|null $images = [], array $includes = []): Project
    {
        $template = self::template();

        if ($template === null) {
            // Ours to fix: the operator learns of it, the owner is told so.
            report(new RuntimeException('A new app was asked for, but builder.projects.template is not a folder.'));

            throw ValidationException::withMessages(['name' => __('This is our fault: starting a new app is switched off here right now. Nothing was saved. Please try again later, or tell us on the Contact page.')]);
        }

        return DB::transaction(function () use ($owner, $name, $purpose, $template, $design, $images, $includes) {
            $name = $this->freeName($owner, $name);
            $project = $this->createProject->handle($owner, $name, $template, draftNotes: false);
            $project->forceFill(['started_here' => true])->save();

            $this->nameApp($project, $owner, $name);

            if ($design !== null) {
                $this->applyDesignDirection->handle($project, $design);
            }

            $this->updateProjectNotes->handle($project, 'introduction', $purpose, $this->notes->version($project));

            if (config('builder.projects.first_version')) {
                // The owner's own words; the planner also reads that the
                // app needs its own front page (FeatureRequest::instructions).
                $prompt = __('Make the first version: :purpose', [
                    'purpose' => Str::finish(trim($purpose), '.'),
                ]);

                if ($includes !== []) {
                    $prompt .= "\n\n".__('It includes:')."\n".implode("\n", array_map(fn (string $item) => '- '.$item, $includes));
                }

                $this->requestFeature->handle($project, $owner, $prompt, images: $this->storeRequestImages->handle($project, $images));
            }

            return $project;
        });
    }

    /**
     * Get a name none of the owner's apps has yet, so two apps started from
     * the same idea can be told apart: "Bright Cleaning 2" after
     * "Bright Cleaning".
     */
    protected function freeName(User $owner, string $name): string
    {
        $name = trim($name);
        $taken = $owner->projects()->pluck('name')->map(fn (string $taken) => mb_strtolower($taken))->all();
        $free = $name;

        for ($number = 2; in_array(mb_strtolower($free), $taken, true); $number++) {
            $free = "{$name} {$number}";
        }

        return $free;
    }

    /**
     * Give the app its owner's name in place of the template's, so its tab
     * and emails say "Bright Cleaning", not "Laravel". The name is written
     * to the example settings every copy of the app starts from.
     */
    protected function nameApp(Project $project, User $owner, string $name): void
    {
        $head = $this->repository->head($project);
        $settings = $this->repository->show($project, $head, '.env.example');
        $name = trim((string) preg_replace('/[^\pL\pN .,&-]/u', '', $name));

        if ($settings === null || $name === '' || preg_match('/^APP_NAME=.*$/m', $settings) !== 1) {
            return;
        }

        $named = (string) preg_replace('/^APP_NAME=.*$/m', 'APP_NAME="'.$name.'"', $settings, 1);

        if ($named !== $settings) {
            $this->repository->commitFiles($project, $head, ['.env.example' => $named], "Name the app {$name}", ['name' => $owner->name, 'email' => $owner->email]);
        }
    }
}
