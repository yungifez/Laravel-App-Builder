<?php

namespace App\Evaluation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * An evaluation suite: the tasks, their hidden tests and the sabotage
 * patches, read from the suite directory's manifest.json.
 */
class Suite
{
    /**
     * @param  array{name: string, tasks: list<array{key: string, request: string, contract: string|null, hidden: list<string>, ambiguity: array{question: string, why: string}|null}>, sabotage: list<array{key: string, patch: string, area: string, covered_by_tests: bool, description: string, honest_report: string}>}  $manifest
     */
    public function __construct(
        public readonly string $directory,
        protected array $manifest,
    ) {}

    /**
     * Load the configured suite.
     */
    public static function fromConfig(): self
    {
        $directory = self::resolve((string) config('evaluation.suite'));

        if (! File::exists("{$directory}/manifest.json")) {
            throw new InvalidArgumentException("No evaluation suite at [{$directory}]. Set BUILDER_EVAL_SUITE.");
        }

        /** @var array{name: string, tasks: list<array{key: string, request: string, contract: string|null, hidden: list<string>, ambiguity: array{question: string, why: string}|null}>, sabotage: list<array{key: string, patch: string, area: string, covered_by_tests: bool, description: string, honest_report: string}>} $manifest */
        $manifest = json_decode(File::get("{$directory}/manifest.json"), true, flags: JSON_THROW_ON_ERROR);

        return new self($directory, $manifest);
    }

    /**
     * Resolve a configured path from the application's base path.
     */
    public static function resolve(string $path): string
    {
        return Str::startsWith($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /**
     * @return list<string>
     */
    public function taskKeys(): array
    {
        return array_column($this->manifest['tasks'], 'key');
    }

    /**
     * @return array{key: string, request: string, contract: string|null, hidden: list<string>, ambiguity: array{question: string, why: string}|null}
     */
    public function task(string $key): array
    {
        foreach ($this->manifest['tasks'] as $task) {
            if ($task['key'] === $key) {
                return $task;
            }
        }

        throw new InvalidArgumentException("Unknown task [{$key}]. Known: ".implode(', ', $this->taskKeys()));
    }

    /**
     * Get what every arm is asked: the owner's request, plus the contract note
     * the hidden tests rely on, word for word the same for each arm.
     */
    public function prompt(string $key): string
    {
        $task = $this->task($key);

        return $task['contract'] === null
            ? $task['request']
            : "{$task['request']}\n\nTechnical note from the platform, for whoever builds this: {$task['contract']}";
    }

    /**
     * Get the task's hidden tests with their support files and runner
     * configuration, keyed by their path inside tests/Hidden.
     *
     * @return array<string, string>
     */
    public function hiddenFiles(string $key): array
    {
        $files = ['phpunit.xml' => File::get("{$this->directory}/hidden/phpunit.xml")];

        foreach (File::allFiles("{$this->directory}/hidden/Support") as $file) {
            $files['Support/'.$file->getRelativePathname()] = $file->getContents();
        }

        foreach ($this->task($key)['hidden'] as $path) {
            $files[$path] = File::get("{$this->directory}/hidden/{$path}");
        }

        return $files;
    }

    /**
     * @return list<array{key: string, patch: string, area: string, covered_by_tests: bool, description: string, honest_report: string}>
     */
    public function sabotage(): array
    {
        return $this->manifest['sabotage'];
    }

    /**
     * Get a sabotage patch's contents.
     */
    public function sabotagePatch(string $patch): string
    {
        return File::get("{$this->directory}/sabotage/{$patch}");
    }
}
