<?php

namespace App\Runs;

use App\Enums\AssumptionLevel;
use App\Enums\Consequence;

/**
 * A decision the planner made where the owner's request was silent, with
 * what a wrong guess would touch, so code decides how much attention it
 * needs.
 */
final readonly class Assumption
{
    /**
     * @param  list<Consequence>  $touches
     */
    public function __construct(
        public string $text,
        public array $touches = [],
        public bool $reversible = true,
        public bool $easierAfterSeeing = false,
    ) {}

    /**
     * Read an assumption from the planner or a saved plan. A touch that is
     * not a known consequence is dropped.
     *
     * @param  array{text: string, touches?: array<int, mixed>, reversible?: bool, easier_after_seeing?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: trim($data['text']),
            touches: self::touches($data['touches'] ?? []),
            reversible: (bool) ($data['reversible'] ?? true),
            easierAfterSeeing: (bool) ($data['easier_after_seeing'] ?? false),
        );
    }

    /**
     * Keep the touches that are known consequences, each once.
     *
     * @param  array<int, mixed>  $touches
     * @return list<Consequence>
     */
    public static function touches(array $touches): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $touch) => is_string($touch) ? Consequence::tryFrom($touch) : null,
            $touches,
        )), SORT_REGULAR));
    }

    /**
     * Get how much attention the decision needs: a look when it touches
     * something that matters or cannot be undone, otherwise none.
     */
    public function level(): AssumptionLevel
    {
        return $this->touches !== [] || ! $this->reversible ? AssumptionLevel::Glance : AssumptionLevel::Quiet;
    }

    /**
     * Put the decisions in the order the owner reads them: those worth a
     * look first, the ones that cannot be undone first among them, then by
     * the most serious thing each touches. Ties keep the planner's order.
     *
     * @param  list<self>  $assumptions
     * @return list<self>
     */
    public static function byAttention(array $assumptions): array
    {
        $rank = fn (self $assumption) => $assumption->level() === AssumptionLevel::Glance
            ? [0, $assumption->reversible ? 1 : 0, min(array_map(fn (Consequence $touch) => $touch->seriousness(), $assumption->touches) ?: [count(Consequence::cases())])]
            : [1, 0, 0];
        $ranked = array_map(fn (self $assumption, int $index) => [...$rank($assumption), $index, $assumption], $assumptions, array_keys($assumptions));

        usort($ranked, fn (array $a, array $b) => array_slice($a, 0, 4) <=> array_slice($b, 0, 4));

        return array_column($ranked, 4);
    }

    /**
     * Get the assumption as stored on the run.
     *
     * @return array{text: string, touches: list<string>, reversible: bool, easier_after_seeing: bool}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'touches' => array_map(fn (Consequence $touch) => $touch->value, $this->touches),
            'reversible' => $this->reversible,
            'easier_after_seeing' => $this->easierAfterSeeing,
        ];
    }
}
