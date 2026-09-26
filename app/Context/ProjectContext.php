<?php

namespace App\Context;

/**
 * What the application's `.builder/` directory says about the product: the
 * project notes and one file per area (capability).
 */
final readonly class ProjectContext
{
    /**
     * The project-wide notes.
     */
    public const PROJECT_FILE = '.builder/project.md';

    /**
     * The directory of capability files.
     */
    public const CAPABILITIES_DIRECTORY = '.builder/capabilities';

    /**
     * @param  array<string, Capability>  $capabilities  Keyed by capability key
     * @param  list<string>  $problems  Context files that could not be read, and why
     */
    public function __construct(
        public ?string $project = null,
        public array $capabilities = [],
        public array $problems = [],
    ) {}

    /**
     * Restore the outline of a project's context from storage.
     *
     * @param  list<array{key: string, name: string, summary: string|null, file: string|null, paths: list<string>, behaviors: list<array{key: string, name: string}>, effects: list<array{to: string, strength: string, reason: string, source: string, observed?: string|null}>, test_files?: list<string>}>  $capabilities
     */
    public static function fromOutline(array $capabilities): self
    {
        $restored = [];

        foreach ($capabilities as $capability) {
            $restored[$capability['key']] = Capability::fromArray($capability);
        }

        return new self(capabilities: $restored);
    }

    /**
     * Determine if the project has no context at all.
     */
    public function isEmpty(): bool
    {
        return blank($this->project) && $this->capabilities === [];
    }

    /**
     * Keep only the given keys that name a known capability.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function known(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, fn (string $key) => isset($this->capabilities[$key]))));
    }

    /**
     * Get the keys of the capabilities a project file belongs to.
     *
     * @return list<string>
     */
    public function claiming(string $path): array
    {
        return array_values(array_map(
            fn (Capability $capability) => $capability->key,
            array_filter($this->capabilities, fn (Capability $capability) => $capability->claims($path)),
        ));
    }

    /**
     * Get the outline of every capability for storage.
     *
     * @return list<array{key: string, name: string, summary: string|null, file: string|null, paths: list<string>, behaviors: list<array{key: string, name: string}>, effects: list<array{to: string, strength: string, reason: string, source: string, observed: string|null}>, test_files?: list<string>}>
     */
    public function outline(): array
    {
        return array_values(array_map(fn (Capability $capability) => $capability->toArray(), $this->capabilities));
    }
}
