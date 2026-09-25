<?php

namespace App\Features;

use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\Generators\ReferenceGenerator;
use App\Models\FeatureRequest;

/**
 * Chooses which platform-owned acceptance suites apply to a request.
 *
 * The choice belongs to the platform, not to the model building the change.
 * Until suites have a catalog of their own, the reference solutions manifest
 * maps requests to suites: by keywords, and for follow-ups by the solution the
 * parent request was classified as (the owner's selected step is not matched,
 * since a model names its own steps). A request it does not know gets no
 * suites, and its change can then only be "unverified".
 */
class AcceptanceSelector
{
    public function __construct(protected FeatureGeneratorManager $generators) {}

    /**
     * Classify the request and get the protected acceptance test files for it.
     *
     * @return array{solution_key: string|null, acceptance: list<string>}
     */
    public function for(FeatureRequest $featureRequest): array
    {
        $generator = $this->generators->driver('reference');

        try {
            $solution = $generator instanceof ReferenceGenerator ? $generator->classify($featureRequest, matchStep: false) : null;
        } catch (CannotGenerateFeature) {
            $solution = null;
        }

        return [
            'solution_key' => $solution['key'] ?? null,
            'acceptance' => $solution['acceptance'] ?? [],
        ];
    }
}
