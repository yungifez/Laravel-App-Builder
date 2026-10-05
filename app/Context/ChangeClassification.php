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
     * @param  list<string>  $contextUpdates  The notes the change rewrote
     * @param  list<string>  $targets  The areas the change is about
     * @param  array{areas: array<string, int>, tests: int, unmapped: list<string>, foundation: list<string>, by_line: list<string>}|null  $observed  What the project's tests showed: the areas whose tests
     *                                                                                                                                                ran the changed code (with how many tests), all such tests, changed PHP files no test ran, and changed foundation code most tests run;
     *                                                                                                                                                null without a test map
     */
    public function __construct(
        public array $requested = [],
        public array $mayAlsoAffect = [],
        public array $unexpected = [],
        public array $unclaimed = [],
        public array $contextUpdates = [],
        public array $targets = [],
        public ?array $observed = null,
    ) {}

    /**
     * Restore a classification from storage.
     *
     * @param  array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>, observed: array{areas: array<string, int>, tests: int, unmapped: list<string>, foundation: list<string>, by_line: list<string>}|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['requested'], $data['may_also_affect'], $data['unexpected'], $data['unclaimed'], $data['context_updates'], $data['targets'], $data['observed']);
    }

    /**
     * Get the classification for storage.
     *
     * @return array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>, observed: array{areas: array<string, int>, tests: int, unmapped: list<string>, foundation: list<string>, by_line: list<string>}|null}
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
            'observed' => $this->observed,
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
     * Get every file the change touched, claimed by an area or not.
     *
     * @return list<string>
     */
    public function changedFiles(): array
    {
        return array_values(array_unique([...array_merge(...array_values($this->requested), ...array_values($this->mayAlsoAffect), ...array_values($this->unexpected)), ...$this->unclaimed]));
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

    /**
     * Say what backs a behaviour change the reviewer described for an area:
     * "tested" when the change touched the area and the area's own tests ran
     * the changed code, "in_change" when the change only touched it, and
     * "not_in_change" when no changed file belongs to it. A line with no
     * area is backed by changed files no area claims.
     *
     * @return 'tested'|'in_change'|'not_in_change'
     */
    public function evidenceFor(?string $area): string
    {
        if ($area === null) {
            return $this->unclaimed !== [] ? 'in_change' : 'not_in_change';
        }

        if (! in_array($area, $this->touched(), true)) {
            return 'not_in_change';
        }

        return ($this->observed['areas'][$area] ?? 0) > 0 ? 'tested' : 'in_change';
    }
}
