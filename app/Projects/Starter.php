<?php

namespace App\Projects;

use Illuminate\Support\Facades\File;
use JsonException;

/**
 * A ready-made idea an owner can start a new app from: a name, what the
 * app is for, a look, and what its first version includes. Each is a JSON
 * file in the configured starters folder, so operators can change or add
 * them without code.
 */
final class Starter
{
    /**
     * @param  list<string>  $includes  What the first version includes, in the owner's words
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $purpose,
        public ?string $design,
        public array $includes,
        public int $order,
    ) {}

    /**
     * Get every starter, in the order they are offered.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $folder = config('builder.projects.starters');

        if (! is_string($folder) || ! File::isDirectory($folder)) {
            return [];
        }

        $starters = array_values(array_filter(array_map(self::load(...), File::glob($folder.'/*.json'))));

        usort($starters, fn (self $a, self $b) => [$a->order, $a->name] <=> [$b->order, $b->name]);

        return $starters;
    }

    /**
     * Read a starter from its file. A file that is not a complete starter
     * is skipped, so one bad file does not hide the others.
     */
    public static function load(string $path): ?self
    {
        try {
            $data = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['name'] ?? null) || ! is_string($data['purpose'] ?? null) || ! is_array($data['includes'] ?? null)) {
            return null;
        }

        return new self(
            key: pathinfo($path, PATHINFO_FILENAME),
            name: $data['name'],
            purpose: $data['purpose'],
            design: is_string($data['design'] ?? null) ? $data['design'] : null,
            includes: array_values(array_filter($data['includes'], is_string(...))),
            order: (int) ($data['order'] ?? PHP_INT_MAX),
        );
    }

    /**
     * Get what the owner sees when picking.
     *
     * @return array{key: string, name: string, purpose: string, design: string|null, includes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'design' => $this->design,
            'includes' => $this->includes,
        ];
    }
}
