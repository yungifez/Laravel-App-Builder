<?php

namespace App\Features;

use App\Features\Contracts\FeatureGenerator;
use App\Features\Generators\ReferenceGenerator;
use Illuminate\Support\Manager;

/**
 * @method FeatureGenerator driver(string|null $driver = null)
 */
class FeatureGeneratorManager extends Manager
{
    /**
     * Get the default feature generator name.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('builder.generator');
    }

    /**
     * Create the reference solutions generator.
     */
    public function createReferenceDriver(): FeatureGenerator
    {
        $path = $this->config->get('builder.generators.reference.path');

        return new ReferenceGenerator(is_string($path) ? $path : null);
    }
}
