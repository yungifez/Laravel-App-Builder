<?php

namespace App\Features\Contracts;

use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\GeneratedChange;
use App\Models\FeatureRequest;

interface FeatureGenerator
{
    /**
     * Produce the change that answers the feature request.
     *
     * Follow-up requests carry their parent request and the key of the step
     * the owner asked to change.
     *
     * @throws CannotGenerateFeature
     */
    public function generate(FeatureRequest $request): GeneratedChange;
}
