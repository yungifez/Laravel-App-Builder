<?php

namespace App\Actions\Projects;

use App\Actions\Context\UpdateProjectNotes;
use App\Actions\Features\RequestFeature;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AddPhoneApp
{
    public function __construct(
        private CreateProject $createProject,
        private ProjectRepository $repository,
        private UpdateProjectNotes $updateProjectNotes,
        private ProjectNotes $notes,
        private RequestFeature $requestFeature,
    ) {}

    /**
     * Get the folder new phone apps start from, or null when there is none.
     */
    public static function template(): ?string
    {
        $template = config('builder.projects.mobile_template');

        return is_string($template) && $template !== '' && is_dir($template) ? $template : null;
    }

    /**
     * Start a phone app for the owner's app. It is a project of its own,
     * so every change to it is planned, built and proved like any other,
     * and it is set to talk to the app it belongs to. The owner's app is
     * asked to let the phone sign in, unless it already does.
     *
     * @throws ValidationException when the app cannot have a phone app, or
     *                             phone apps are switched off here.
     */
    public function handle(Project $project): Project
    {
        if ($project->parent_id !== null) {
            throw ValidationException::withMessages(['phone_app' => __('This is already a phone app.')]);
        }

        if ($project->phoneApp()->exists()) {
            throw ValidationException::withMessages(['phone_app' => __('This app already has a phone app.')]);
        }

        $template = self::template();

        if ($template === null) {
            // Ours to fix: the operator learns of it, the owner is told so.
            report(new RuntimeException('A phone app was asked for, but builder.projects.mobile_template is not a folder.'));

            throw ValidationException::withMessages(['phone_app' => __('This is our fault: phone apps are switched off here right now. Nothing was saved. Please try again later.')]);
        }

        $owner = $project->owner;

        $phone = DB::transaction(function () use ($project, $owner, $template) {
            $name = $this->freeName($project, __(':name phone app', ['name' => $project->name]));
            $phone = $this->createProject->handle($owner, $name, $template, draftNotes: false);
            $phone->forceFill(['parent_id' => $project->id, 'started_here' => true])->save();

            $this->settle($phone, $project, $name);
            $this->updateProjectNotes->handle($phone, 'introduction', __('The phone app for :name. It shows what :name holds and talks to it over the internet; the data stays in :name.', ['name' => $project->name]), $this->notes->version($phone));

            return $phone;
        });

        if (! $this->signsInPhones($project)) {
            $this->requestFeature->handle($project, $owner, (string) config('builder.projects.mobile_request'));
        }

        return $phone;
    }

    /**
     * Whether the owner's app already hands out sign-in tokens, as the
     * phone app's sign-in needs.
     */
    protected function signsInPhones(Project $project): bool
    {
        $routes = $this->repository->exists($project) ? $this->repository->show($project, $this->repository->head($project), 'routes/api.php') : null;

        return str_contains((string) $routes, 'createToken(');
    }

    /**
     * The settings every copy of the phone app starts from: its name, the
     * address of the app it talks to, and its store id.
     */
    protected function settle(Project $phone, Project $project, string $name): void
    {
        $head = $this->repository->head($phone);
        $settings = (string) $this->repository->show($phone, $head, '.env.example');
        $values = [
            'APP_NAME' => '"'.trim((string) preg_replace('/[^\pL\pN .,&-]/u', '', $name)).'"',
            'BACKEND_URL' => (string) $project->live_url,
            'NATIVEPHP_APP_ID' => self::appId($project->name, $phone->id),
        ];

        foreach ($values as $key => $value) {
            $line = "{$key}={$value}";
            $settings = preg_match("/^{$key}=.*$/m", $settings) === 1
                ? (string) preg_replace("/^{$key}=.*$/m", addcslashes($line, '\\$'), $settings)
                : rtrim($settings)."\n{$line}\n";
        }

        $this->repository->commitFiles($phone, $head, ['.env.example' => $settings], 'Point the phone app at '.$project->name, ['name' => $phone->owner->name, 'email' => $phone->owner->email]);
    }

    /**
     * The phone app's store id: the configured prefix, then the name of
     * the app it belongs to in lowercase letters and digits,
     * "com.example.brightcleaning". Stores need each part to start with a
     * letter.
     */
    public static function appId(string $name, int $id): string
    {
        $name = (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($name)));

        if (preg_match('/^[a-z]/', $name) !== 1) {
            $name = 'app'.$name.$id;
        }

        return config('builder.projects.mobile_app_id_prefix').'.'.$name;
    }

    /**
     * Get a name none of the owner's apps has yet.
     */
    protected function freeName(Project $project, string $name): string
    {
        $taken = $project->owner->projects()->pluck('name')->map(fn (string $taken) => mb_strtolower($taken))->all();
        $free = $name;

        for ($number = 2; in_array(mb_strtolower($free), $taken, true); $number++) {
            $free = "{$name} {$number}";
        }

        return $free;
    }
}
