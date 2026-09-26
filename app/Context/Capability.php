<?php

namespace App\Context;

use App\Context\Exceptions\InvalidContextFile;
use App\Enums\EffectStrength;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One area of the product as its context file in `.builder/capabilities/`
 * describes it: what it is, which code belongs to it, its behaviours, what
 * it may also affect, and the owner-readable notes.
 */
final readonly class Capability
{
    /**
     * Where Effects may come from.
     *
     * @var list<string>
     */
    public const EFFECT_SOURCES = ['agent', 'package', 'analysis', 'owner'];

    /**
     * @param  list<string>  $paths  Glob patterns for the code that belongs to the area
     * @param  list<array{key: string, name: string}>  $behaviors
     * @param  list<Effect>  $effects
     * @param  list<string>  $testFiles  The project's test files the area claims
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $summary = null,
        public array $paths = [],
        public array $behaviors = [],
        public array $effects = [],
        public ?string $file = null,
        public string $notes = '',
        public array $testFiles = [],
    ) {}

    /**
     * Read a capability file: optional YAML frontmatter, then Markdown notes.
     * Without frontmatter, the key comes from the file name.
     *
     * @throws InvalidContextFile
     */
    public static function fromMarkdown(string $file, string $markdown): self
    {
        $data = [];
        $notes = $markdown;

        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $markdown, $matches) === 1) {
            try {
                $parsed = Yaml::parse($matches[1], Yaml::PARSE_DATETIME);
            } catch (ParseException $exception) {
                throw InvalidContextFile::at($file, $exception->getMessage());
            }

            if (! is_array($parsed)) {
                throw InvalidContextFile::at($file, __('The frontmatter must be a list of fields.'));
            }

            $data = $parsed;
            $notes = $matches[2];
        }

        $data['capability'] ??= pathinfo($file, PATHINFO_FILENAME);

        foreach ($data['effects'] ?? [] as $index => $effect) {
            if (is_array($effect) && ($effect['observed'] ?? null) instanceof DateTimeInterface) {
                $data['effects'][$index]['observed'] = $effect['observed']->format('Y-m-d');
            }
        }

        $validator = Validator::make($data, [
            'capability' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'max:60'],
            'summary' => ['nullable', 'string', 'max:500'],
            'paths' => ['sometimes', 'array', 'max:50'],
            'paths.*' => ['required', 'string', 'max:300'],
            'behaviors' => ['sometimes', 'array', 'max:50'],
            'behaviors.*.key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'max:60', 'distinct'],
            'behaviors.*.name' => ['required', 'string', 'max:200'],
            'effects' => ['sometimes', 'array', 'max:30'],
            'effects.*.to' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'max:60'],
            'effects.*.strength' => ['required', Rule::enum(EffectStrength::class)],
            'effects.*.reason' => ['required', 'string', 'max:500'],
            'effects.*.source' => ['required', Rule::in(self::EFFECT_SOURCES)],
            'effects.*.observed' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            throw InvalidContextFile::at($file, implode(' ', $validator->errors()->all()));
        }

        /** @var array{capability: string, summary?: string|null, paths?: array<int, string>, behaviors?: array<int, array{key: string, name: string}>, effects?: array<int, array{to: string, strength: string, reason: string, source: string, observed?: string|null}>, test_files?: list<string>} $valid */
        $valid = $validator->validated();

        $name = preg_match('/^#\s+(.+)$/m', $notes, $heading) === 1
            ? trim($heading[1])
            : Str::headline($valid['capability']);

        return new self(
            key: $valid['capability'],
            name: $name,
            summary: $valid['summary'] ?? null,
            paths: array_values($valid['paths'] ?? []),
            behaviors: array_values(array_map(fn (array $behavior) => ['key' => $behavior['key'], 'name' => $behavior['name']], $valid['behaviors'] ?? [])),
            effects: array_values(array_map(fn (array $effect) => Effect::fromArray($effect), $valid['effects'] ?? [])),
            file: $file,
            notes: trim($notes),
        );
    }

    /**
     * Restore a capability from its stored outline (without its notes).
     *
     * @param  array{key: string, name: string, summary: string|null, file: string|null, paths: list<string>, behaviors: list<array{key: string, name: string}>, effects: list<array{to: string, strength: string, reason: string, source: string, observed?: string|null}>, test_files?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            name: $data['name'],
            summary: $data['summary'],
            paths: $data['paths'],
            behaviors: $data['behaviors'],
            effects: array_map(fn (array $effect) => Effect::fromArray($effect), $data['effects']),
            file: $data['file'],
            testFiles: $data['test_files'] ?? [],
        );
    }

    /**
     * Get the capability's outline for storage: everything except its notes.
     *
     * @return array{key: string, name: string, summary: string|null, file: string|null, paths: list<string>, behaviors: list<array{key: string, name: string}>, effects: list<array{to: string, strength: string, reason: string, source: string, observed: string|null}>, test_files?: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'summary' => $this->summary,
            'file' => $this->file,
            'paths' => $this->paths,
            'behaviors' => $this->behaviors,
            'effects' => array_map(fn (Effect $effect) => $effect->toArray(), $this->effects),
            'test_files' => $this->testFiles,
        ];
    }

    /**
     * Determine if the project's test suite check runs the file.
     */
    public static function runBySuite(string $path): bool
    {
        return Str::startsWith($path, (array) config('builder.verification.suite_paths'));
    }

    /**
     * Get a copy that knows which of the project's test files the area claims.
     *
     * @param  list<string>  $files  The project's files
     */
    public function withTestFilesFrom(array $files): self
    {
        $tests = array_values(array_filter($files, fn (string $path) => self::runBySuite($path) && $this->claims($path)));

        return new self($this->key, $this->name, $this->summary, $this->paths, $this->behaviors, $this->effects, $this->file, $this->notes, $tests);
    }

    /**
     * Determine if a project file belongs to this area.
     */
    public function claims(string $path): bool
    {
        foreach ($this->paths as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
