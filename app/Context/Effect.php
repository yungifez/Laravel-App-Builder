<?php

namespace App\Context;

use App\Enums\EffectStrength;

/**
 * A soft hint that changing one area may matter to another ("inviting a
 * member may also affect billing"). Not a dependency, a contract or a
 * requirement: agents and owners decide whether it is relevant.
 */
final readonly class Effect
{
    public function __construct(
        public string $to,
        public EffectStrength $strength,
        public string $reason,
        public string $source,
        public ?string $observed = null,
    ) {}

    /**
     * Restore an Effect from its stored form.
     *
     * @param  array{to: string, strength: string, reason: string, source: string, observed?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['to'], EffectStrength::from($data['strength']), $data['reason'], $data['source'], $data['observed'] ?? null);
    }

    /**
     * Get the Effect in its stored form.
     *
     * @return array{to: string, strength: string, reason: string, source: string, observed: string|null}
     */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'strength' => $this->strength->value,
            'reason' => $this->reason,
            'source' => $this->source,
            'observed' => $this->observed,
        ];
    }
}
