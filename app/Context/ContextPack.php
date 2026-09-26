<?php

namespace App\Context;

use App\Enums\ContextMode;

/**
 * The project context compiled for one run: the text the agents receive,
 * which files went into it and roughly how many tokens each cost, and the
 * outline of every area for classifying the change afterwards.
 */
final readonly class ContextPack
{
    /**
     * @param  list<string>  $targets  The areas the change is about
     * @param  list<array{file: string, tokens: int}>  $included
     * @param  list<array{key: string, name: string, summary: string|null, file: string|null, paths: list<string>, behaviors: list<array{key: string, name: string}>, effects: list<array{to: string, strength: string, reason: string, source: string, observed: string|null}>}>  $outline
     * @param  list<string>  $problems
     */
    public function __construct(
        public ContextMode $mode,
        public array $targets,
        public string $text,
        public array $included = [],
        public array $outline = [],
        public array $problems = [],
    ) {}

    /**
     * Restore a pack saved on a run.
     *
     * @param  array{mode: string, targets: list<string>, text: string, included: list<array{file: string, tokens: int}>, outline: list<array{key: string, name: string, summary: string|null, file: string|null, paths: list<string>, behaviors: list<array{key: string, name: string}>, effects: list<array{to: string, strength: string, reason: string, source: string, observed: string|null}>}>, problems: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ContextMode::from($data['mode']), $data['targets'], $data['text'], $data['included'], $data['outline'], $data['problems']);
    }

    /**
     * Get the pack as stored on the run.
     *
     * @return array{mode: string, targets: list<string>, text: string, included: list<array{file: string, tokens: int}>, outline: list<array{key: string, name: string, summary: string|null, file: string|null, paths: list<string>, behaviors: list<array{key: string, name: string}>, effects: list<array{to: string, strength: string, reason: string, source: string, observed: string|null}>}>, problems: list<string>}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'targets' => $this->targets,
            'text' => $this->text,
            'included' => $this->included,
            'outline' => $this->outline,
            'problems' => $this->problems,
        ];
    }

    /**
     * Get the estimated tokens of everything included.
     */
    public function tokens(): int
    {
        return array_sum(array_column($this->included, 'tokens'));
    }

    /**
     * Get the project's context as it was when the pack was compiled.
     */
    public function projectContext(): ProjectContext
    {
        return ProjectContext::fromOutline($this->outline);
    }
}
