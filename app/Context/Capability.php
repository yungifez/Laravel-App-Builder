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
 * One area of the product as its notes file under `capabilities/`
 * describes it: what it is, which code belongs to it, its behaviours, what
 * it may also affect, and the owner-readable notes.
 */
final readonly class Capability
{
    /**
     * Where Effects may come from. "tests" is observed evidence: tests that
     * belong to the other area ran this area's code (direction 22).
     * "history" is kept changes about this area that also changed the other.
     *
     * @var list<string>
     */
    public const EFFECT_SOURCES = ['agent', 'package', 'analysis', 'owner', 'tests', 'history'];

    /**
     * @param  list<string>  $paths  Glob patterns for the code that belongs to the area
     * @param  list<array{key: string, name: string}>  $behaviors
     * @param  list<Effect>  $effects
     * @param  list<string>  $testFiles  The project's test files the area claims
     * @param  list<string>  $reachedBy  The test files whose tests ran the area's code when last observed, most tests first
     * @param  list<string>  $notConnected  Areas the owner says this one does not affect, whatever the evidence
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
        public array $reachedBy = [],
        public array $notConnected = [],
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
            'not_connected' => ['sometimes', 'array', 'max:50'],
            'not_connected.*' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'max:60', 'distinct'],
        ]);

        if ($validator->fails()) {
            throw InvalidContextFile::at($file, implode(' ', $validator->errors()->all()));
        }

        /** @var array{capability: string, summary?: string|null, paths?: array<int, string>, behaviors?: array<int, array{key: string, name: string}>, effects?: array<int, array{to: string, strength: string, reason: string, source: string, observed?: string|null}>, test_files?: list<string>, not_connected?: array<int, string>} $valid */
        $valid = $validator->validated();

        $name = preg_match('/^#\s+(.+)$/m', $notes, $heading) === 1
            ? trim($heading[1])
            : Str::headline($valid['capability']);

        // Text the owner and agents write: no list means the owner has
        // ruled out no connection.
        $notConnected = array_values($valid['not_connected'] ?? []);

        return new self(
            key: $valid['capability'],
            name: $name,
            summary: $valid['summary'] ?? null,
            paths: array_values($valid['paths'] ?? []),
            behaviors: array_values(array_map(fn (array $behavior) => ['key' => $behavior['key'], 'name' => $behavior['name']], $valid['behaviors'] ?? [])),
            effects: self::connected(array_map(fn (array $effect) => Effect::fromArray($effect), $valid['effects'] ?? []), $notConnected),
            file: $file,
            notes: trim($notes),
            notConnected: $notConnected,
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
     * Get the rules the notes list under "## Rules", one per bullet.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        if (preg_match('/^##\s+Rules\s*$(.*?)(?=^##\s|\z)/ms', $this->notes, $section) !== 1) {
            return [];
        }

        $rules = [];

        foreach (preg_split('/\R/', trim($section[1])) ?: [] as $line) {
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $bullet) === 1) {
                $rules[] = trim($bullet[1]);
            } elseif (trim($line) !== '' && $rules !== []) {
                $rules[array_key_last($rules)] .= ' '.trim($line);
            }
        }

        return $rules;
    }

    /**
     * Determine if the project's test suite check runs the file.
     */
    public static function runBySuite(string $path): bool
    {
        return Str::startsWith($path, (array) config('builder.verification.suite_paths'))
            && Str::endsWith($path, (array) config('builder.verification.suite_suffixes'));
    }

    /**
     * Describe where a test must live for the suite check to run it, such as
     * "tests/, in a file whose name ends in Test.php".
     */
    public static function suiteLocation(): string
    {
        return __(':paths, in a file whose name ends in :suffixes', [
            'paths' => implode(', ', (array) config('builder.verification.suite_paths')),
            'suffixes' => implode(' or ', (array) config('builder.verification.suite_suffixes')),
        ]);
    }

    /**
     * Get a copy that knows which of the project's test files the area claims.
     *
     * @param  list<string>  $files  The project's files
     */
    public function withTestFilesFrom(array $files): self
    {
        $tests = array_values(array_filter($files, fn (string $path) => self::runBySuite($path) && $this->claims($path)));

        return new self($this->key, $this->name, $this->summary, $this->paths, $this->behaviors, $this->effects, $this->file, $this->notes, $tests, $this->reachedBy, $this->notConnected);
    }

    /**
     * Keep only the Effects on areas the owner has not ruled out.
     *
     * @param  array<int, Effect>  $effects
     * @param  list<string>  $notConnected
     * @return list<Effect>
     */
    protected static function connected(array $effects, array $notConnected): array
    {
        return array_values(array_filter($effects, fn (Effect $effect) => ! in_array($effect->to, $notConnected, true)));
    }

    /**
     * Get a copy with more Effects, such as those observed from tests. The
     * owner's "not connected" holds over any evidence.
     *
     * @param  list<Effect>  $effects
     */
    public function withEffects(array $effects): self
    {
        return new self($this->key, $this->name, $this->summary, $this->paths, $this->behaviors, [...$this->effects, ...self::connected($effects, $this->notConnected)], $this->file, $this->notes, $this->testFiles, $this->reachedBy, $this->notConnected);
    }

    /**
     * Get a copy that knows which test files ran the area's code.
     *
     * @param  list<string>  $files
     */
    public function withReachedBy(array $files): self
    {
        return new self($this->key, $this->name, $this->summary, $this->paths, $this->behaviors, $this->effects, $this->file, $this->notes, $this->testFiles, $files, $this->notConnected);
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
