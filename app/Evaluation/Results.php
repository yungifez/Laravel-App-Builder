<?php

namespace App\Evaluation;

use Illuminate\Support\Facades\File;

/**
 * Reads and writes the evaluation's results: one directory per task, with a
 * subdirectory per arm.
 */
class Results
{
    public function __construct(public readonly string $directory) {}

    /**
     * Use the configured results directory.
     */
    public static function fromConfig(): self
    {
        return new self(Suite::resolve((string) config('evaluation.results')));
    }

    /**
     * Get a path inside the results.
     */
    public function path(string ...$segments): string
    {
        return implode(DIRECTORY_SEPARATOR, [rtrim($this->directory, DIRECTORY_SEPARATOR), ...$segments]);
    }

    /**
     * Write data as pretty JSON, or text as it is.
     *
     * @param  array<array-key, mixed>|string  $contents
     */
    public function put(string $path, array|string $contents): void
    {
        $target = $this->path($path);

        File::ensureDirectoryExists(dirname($target));
        File::put($target, is_string($contents) ? $contents : (string) json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * Read a JSON file, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $path): ?array
    {
        $target = $this->path($path);

        if (! File::exists($target)) {
            return null;
        }

        /** @var array<string, mixed> */
        return json_decode(File::get($target), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Read a text file, or null when it does not exist.
     */
    public function text(string $path): ?string
    {
        $target = $this->path($path);

        return File::exists($target) ? File::get($target) : null;
    }
}
