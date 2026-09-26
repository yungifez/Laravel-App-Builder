<?php

namespace App\Context;

/**
 * Where a change landed, by area: in the areas it was about, in areas their
 * Effects named, or somewhere else. Decided from the changed paths alone.
 */
final readonly class ChangeClassification
{
    /**
     * @param  array<string, list<string>>  $requested  Changed files per requested area
     * @param  array<string, list<string>>  $mayAlsoAffect  Changed files per area a requested area's Effects name
     * @param  array<string, list<string>>  $unexpected  Changed files per other area
     * @param  list<string>  $unclaimed  Changed files no area claims
     * @param  list<string>  $contextUpdates  Changed files under `.builder/`
     * @param  list<string>  $targets  The areas the change is about
     */
    public function __construct(
        public array $requested = [],
        public array $mayAlsoAffect = [],
        public array $unexpected = [],
        public array $unclaimed = [],
        public array $contextUpdates = [],
        public array $targets = [],
    ) {}

    /**
     * Restore a classification from storage.
     *
     * @param  array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['requested'], $data['may_also_affect'], $data['unexpected'], $data['unclaimed'], $data['context_updates'], $data['targets']);
    }

    /**
     * Get the classification for storage.
     *
     * @return array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>}
     */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested,
            'may_also_affect' => $this->mayAlsoAffect,
            'unexpected' => $this->unexpected,
            'unclaimed' => $this->unclaimed,
            'context_updates' => $this->contextUpdates,
            'targets' => $this->targets,
        ];
    }

    /**
     * Get the keys of every area the change touched.
     *
     * @return list<string>
     */
    public function touched(): array
    {
        return array_values(array_unique([...array_keys($this->requested), ...array_keys($this->mayAlsoAffect), ...array_keys($this->unexpected)]));
    }

    /**
     * Get which section an area's changes belong to: an area the change is
     * about is requested even when no file it claims changed.
     */
    public function sectionFor(?string $area): string
    {
        return match (true) {
            $area !== null && (isset($this->requested[$area]) || in_array($area, $this->targets, true)) => 'requested',
            $area !== null && isset($this->mayAlsoAffect[$area]) => 'may_also_affect',
            $area !== null && isset($this->unexpected[$area]) => 'unexpected',
            default => 'other',
        };
    }
}
