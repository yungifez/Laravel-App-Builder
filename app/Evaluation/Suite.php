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
     * @param  array{name: string, tasks: list<array{key: string, request: string, contract: string|null, hidden: list<string>, ambiguity: array{question: string, why: string}|null, category?: string, guards?: list<string>, reference?: string, areas?: list<string>, authorized?: array{rule: string, now: string}|null}>, sabotage: list<array{key: string, patch: string, area: string, covered_by_tests: bool, description: string, honest_report: string, caught_by?: list<string>}>, in_change?: list<array{key: string, task: string, patches: array<string, string>, area: string, covered_by_tests: bool, description: string, honest_report: string}>}  $manifest
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

        /** @var array{name: string, tasks: list<array{key: string, request: string, contract: string|null, hidden: list<string>, ambiguity: array{question: string, why: string}|null, category?: string, guards?: list<string>, reference?: string, areas?: list<string>, authorized?: array{rule: string, now: string}|null}>, sabotage: list<array{key: string, patch: string, area: string, covered_by_tests: bool, description: string, honest_report: string, caught_by?: list<string>}>, in_change?: list<array{key: string, task: string, patches: array<string, string>, area: string, covered_by_tests: bool, description: string, honest_report: string}>} $manifest */
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
     * @return array{key: string, request: string, contract: string|null, hidden: list<string>, ambiguity: array{question: string, why: string}|null, category?: string, guards?: list<string>, reference?: string, areas?: list<string>, authorized?: array{rule: string, now: string}|null}
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

        $task = $this->task($key);

        foreach ([...$task['hidden'], ...($task['guards'] ?? [])] as $path) {
            $files[$path] = File::get("{$this->directory}/hidden/{$path}");
        }

        return $files;
    }

    /**
     * Get a task's reference solution, or null when the suite has none.
     */
    public function reference(string $key): ?string
    {
        $reference = $this->task($key)['reference'] ?? null;

        return $reference === null ? null : File::get("{$this->directory}/reference/{$reference}");
    }

    /**
     * Get the project's requirements as written: every file in its `.builder/`
     * notes, with its path. The generic reviewer gets these in place of the
     * plan and preservation clauses the pipeline derives from them.
     */
    public static function requirements(string $project): string
    {
        $sections = [];

        foreach (File::allFiles("{$project}/.builder") as $file) {
            $sections[$file->getRelativePathname()] = "### .builder/{$file->getRelativePathname()}\n\n".trim($file->getContents());
        }

        ksort($sections);

        return implode("\n\n", $sections);
    }

    /**
     * @return list<array{key: string, patch: string, area: string, covered_by_tests: bool, description: string, honest_report: string, caught_by?: list<string>}>
     */
    public function sabotage(): array
    {
        return $this->manifest['sabotage'];
    }

    /**
     * Get every defect planted in one task: the suite-wide sabotage, then the
     * defects planted inside the code each arm wrote for that task. An
     * in-change defect has its own patch per arm, because each arm wrote
     * different code.
     *
     * @return list<array{key: string, patch?: string, patches?: array<string, string>, area: string, covered_by_tests: bool, description: string, honest_report: string}>
     */
    public function sabotageFor(string $task): array
    {
        return [
            ...$this->manifest['sabotage'],
            ...array_values(array_filter($this->manifest['in_change'] ?? [], fn (array $sabotage) => $sabotage['task'] === $task)),
        ];
    }

    /**
     * Get the patch that plants a defect in one arm's change, or null when
     * that arm's code has no place for it.
     *
     * @param  array{patch?: string, patches?: array<string, string>}  $sabotage
     */
    public function sabotagePatchFor(array $sabotage, string $arm): ?string
    {
        $patch = isset($sabotage['patches']) ? ($sabotage['patches'][$arm] ?? null) : ($sabotage['patch'] ?? null);

        return $patch === null ? null : $this->sabotagePatch($patch);
    }

    /**
     * Get a sabotage patch's contents.
     */
    public function sabotagePatch(string $patch): string
    {
        return File::get("{$this->directory}/sabotage/{$patch}");
    }
}
