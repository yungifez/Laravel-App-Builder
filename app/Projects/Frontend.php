<?php

namespace App\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * How an app draws its screens: Inertia with Vue, React or Svelte, Livewire,
 * or plain Blade views. The builder works on any Laravel app, whatever its
 * screens are made with. Each part that needs to know where the screens
 * live asks this, so knowledge of one stack never spreads through the rest.
 *
 * The stacks are listed in config/builder.php under "frontends". An app is
 * the first stack whose packages it requires; the last one requires nothing,
 * so every Laravel app is one of them.
 */
final readonly class Frontend
{
    /**
     * @param  list<string>  $pages  The folders its screens live in
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $pages,
    ) {}

    /**
     * Name the stack of an app from its composer.json and package.json, as
     * text. A missing or unreadable manifest requires nothing.
     */
    public static function detect(?string $composer, ?string $package): self
    {
        $required = [
            'composer' => self::packages($composer),
            'npm' => self::packages($package),
        ];

        /** @var array<string, array{label: string, composer?: list<string>, npm?: list<string>, pages?: list<string>}> $frontends */
        $frontends = Config::array('builder.frontends');

        foreach ($frontends as $key => $frontend) {
            $needs = ['composer' => $frontend['composer'] ?? [], 'npm' => $frontend['npm'] ?? []];

            if (array_diff($needs['composer'], $required['composer']) === [] && array_diff($needs['npm'], $required['npm']) === []) {
                return new self($key, $frontend['label'], $frontend['pages'] ?? []);
            }
        }

        return new self('unknown', 'Laravel', []);
    }

    /**
     * Name the stack of the app in a folder.
     */
    public static function in(string $path): self
    {
        $read = fn (string $file) => File::isFile("{$path}/{$file}") ? File::get("{$path}/{$file}") : null;

        return self::detect($read('composer.json'), $read('package.json'));
    }

    /**
     * Name the stack of a project at a revision.
     */
    public static function of(ProjectRepository $repository, Project $project, string $revision): self
    {
        return self::detect($repository->show($project, $revision, 'composer.json'), $repository->show($project, $revision, 'package.json'));
    }

    /**
     * Get the names of the packages a manifest requires, for running and
     * for development alike.
     *
     * @return list<string>
     */
    protected static function packages(?string $manifest): array
    {
        $contents = $manifest === null ? null : json_decode($manifest, true);

        if (! is_array($contents)) {
            return [];
        }

        return array_map(strval(...), array_keys([
            ...(array) ($contents['require'] ?? []),
            ...(array) ($contents['require-dev'] ?? []),
            ...(array) ($contents['dependencies'] ?? []),
            ...(array) ($contents['devDependencies'] ?? []),
        ]));
    }
}
