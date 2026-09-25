<?php

namespace App\Features;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Collects the platform-owned acceptance tests for a change.
 *
 * The suite directory holds the runner configuration (phpunit.xml), shared
 * helpers (Support/) and the test files. It lives outside every workspace, so
 * a generated change cannot edit what it is verified against.
 */
class AcceptanceSuite
{
    /**
     * Directory inside the workspace that the suite is written to.
     */
    public const WORKSPACE_DIRECTORY = 'tests/Acceptance';

    public function __construct(protected ?string $path) {}

    /**
     * Build a suite from the configured directory.
     */
    public static function fromConfig(): self
    {
        $path = config('builder.verification.acceptance.path');

        return new self(is_string($path) && $path !== '' ? $path : null);
    }

    /**
     * Get the runner configuration, shared helpers and the selected test
     * files, keyed by their path inside the workspace.
     *
     * @param  list<string>  $tests  Test files relative to the suite directory
     * @return array<string, string>
     *
     * @throws RuntimeException when the suite directory or a listed file is missing.
     */
    public function files(array $tests): array
    {
        $directory = $this->directory();
        $files = [];

        foreach (['phpunit.xml', ...$this->supportFiles($directory), ...$tests] as $relative) {
            if (str_contains($relative, '..') || ! File::isFile("{$directory}/{$relative}")) {
                throw new RuntimeException("Acceptance file [{$relative}] was not found.");
            }

            $files[self::WORKSPACE_DIRECTORY.'/'.$relative] = File::get("{$directory}/{$relative}");
        }

        return $files;
    }

    /**
     * Get the command that runs the suite with the platform's runner configuration.
     *
     * @return list<string>
     */
    public function command(): array
    {
        return ['php', 'vendor/bin/phpunit', '--configuration', self::WORKSPACE_DIRECTORY.'/phpunit.xml'];
    }

    /**
     * Resolve the suite directory.
     */
    protected function directory(): string
    {
        if ($this->path === null) {
            throw new RuntimeException('No acceptance suite directory is configured (BUILDER_ACCEPTANCE_PATH).');
        }

        $directory = Str::startsWith($this->path, DIRECTORY_SEPARATOR) ? $this->path : base_path($this->path);

        if (! File::isDirectory($directory)) {
            throw new RuntimeException("The acceptance suite directory [{$this->path}] does not exist.");
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * List the shared helper files, relative to the suite directory.
     *
     * @return list<string>
     */
    protected function supportFiles(string $directory): array
    {
        if (! File::isDirectory("{$directory}/Support")) {
            return [];
        }

        $files = array_map(
            fn (SplFileInfo $file) => 'Support/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()),
            File::allFiles("{$directory}/Support"),
        );

        sort($files);

        return $files;
    }
}
