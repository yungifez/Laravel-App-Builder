<?php

namespace App\Projects;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;

/**
 * A look an owner can start a new app with: its colours for light and dark
 * mode, font, corner radius, and how it should (and should never) feel.
 * Each look is a JSON file in the configured designs folder, so operators
 * can change or add looks without code.
 */
final class DesignDirection
{
    /**
     * @param  list<string>  $feel  How the app should feel
     * @param  list<string>  $avoid  What the app should never do
     * @param  list<int>  $weights  The font weights to load
     * @param  array<string, string>  $light  Colour tokens by name, for light mode
     * @param  array<string, string>  $dark  Colour tokens by name, for dark mode
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public array $feel,
        public array $avoid,
        public string $font,
        public array $weights,
        public string $radius,
        public array $light,
        public array $dark,
        public int $order,
    ) {}

    /**
     * Get every look, in the order they are offered.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $folder = config('builder.projects.designs');

        if (! is_string($folder) || ! File::isDirectory($folder)) {
            return [];
        }

        $directions = [];

        foreach (File::glob($folder.'/*.json') as $path) {
            $direction = self::load($path);

            if ($direction !== null) {
                $directions[] = $direction;
            }
        }

        usort($directions, fn (self $a, self $b) => [$a->order, $a->name] <=> [$b->order, $b->name]);

        return $directions;
    }

    /**
     * Find a look by its key (its file name).
     */
    public static function find(string $key): ?self
    {
        foreach (self::all() as $direction) {
            if ($direction->key === $key) {
                return $direction;
            }
        }

        return null;
    }

    /**
     * Read a look from its file. A file that is not a complete look is
     * skipped, so one bad file does not hide the others.
     */
    public static function load(string $path): ?self
    {
        try {
            $data = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['name'] ?? null) || ! is_array($data['font'] ?? null)
            || ! is_string($data['font']['family'] ?? null) || preg_match('/^[\w -]+$/', $data['font']['family']) !== 1
            || ! is_array($data['light'] ?? null) || ! is_array($data['dark'] ?? null)) {
            return null;
        }

        // "bunny" names the font and its weights, as in "nunito:400,600,700".
        $weights = array_values(array_filter(
            array_map(intval(...), explode(',', Str::after((string) ($data['font']['bunny'] ?? ''), ':'))),
            fn (int $weight) => $weight > 0,
        ));

        return new self(
            key: pathinfo($path, PATHINFO_FILENAME),
            name: $data['name'],
            description: (string) ($data['description'] ?? ''),
            feel: self::strings($data['feel'] ?? []),
            avoid: self::strings($data['avoid'] ?? []),
            font: $data['font']['family'],
            weights: $weights === [] ? [400, 500, 600] : $weights,
            radius: (string) ($data['radius'] ?? '0.5rem'),
            light: self::tokens($data['light']),
            dark: self::tokens($data['dark']),
            order: (int) ($data['order'] ?? PHP_INT_MAX),
        );
    }

    /**
     * Get what the owner sees when picking: the name, one line about it,
     * and a few colours and the corner radius to draw it with.
     *
     * @return array{key: string, name: string, description: string, font: string, radius: string, colors: array<string, string>}
     */
    public function preview(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'font' => $this->font,
            'radius' => $this->radius,
            'colors' => array_intersect_key($this->light, array_flip(['background', 'foreground', 'primary', 'primary-foreground', 'accent', 'muted-foreground', 'border'])),
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    /**
     * Keep only token names and values that are safe to write into CSS.
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    private static function tokens(array $values): array
    {
        $tokens = [];

        foreach ($values as $name => $value) {
            if (is_string($name) && preg_match('/^[a-z][a-z0-9-]*$/', $name) === 1
                && is_string($value) && preg_match('/^[#\w\s%.,()\/-]+$/', $value) === 1) {
                $tokens[$name] = $value;
            }
        }

        return $tokens;
    }
}
