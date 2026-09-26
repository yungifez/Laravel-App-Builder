<?php

namespace App\VisualEditing;

use InvalidArgumentException;
use Stringable;

/**
 * Where an element was written: "resources/js/pages/Home.vue:12:9", as the
 * preview's source locator stamps it. An instance location is where a
 * component is used, rather than where an element is defined.
 */
class SourceLocation implements Stringable
{
    public function __construct(
        public string $file,
        public int $line,
        public int $column,
        public bool $instance = false,
    ) {}

    /**
     * Read a stamped location.
     *
     * @throws InvalidArgumentException when it is not a location in the project.
     */
    public static function parse(string $location, bool $instance = false): self
    {
        if (preg_match('/^([\w@.\/-]+\.vue):(\d+):(\d+)$/', $location, $match) !== 1 || in_array('..', explode('/', $match[1]), true) || str_starts_with($match[1], '/')) {
            throw new InvalidArgumentException("Invalid source location [{$location}].");
        }

        return new self($match[1], (int) $match[2], (int) $match[3], $instance);
    }

    public function __toString(): string
    {
        return "{$this->file}:{$this->line}:{$this->column}";
    }
}
