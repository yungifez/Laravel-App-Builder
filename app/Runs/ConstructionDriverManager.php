<?php

namespace App\Runs;

use App\Features\FeatureGeneratorManager;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Drivers\ScriptedDriver;
use App\Runs\Drivers\SdkDriver;
use App\Runs\Drivers\WorkerDriver;
use Illuminate\Support\Manager;

/**
 * @method ConstructionDriver driver(string|null $driver = null)
 */
class ConstructionDriverManager extends Manager
{
    /**
     * Get the default construction driver name.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('builder.construction.driver');
    }

    /**
     * Create the scripted driver, which applies the generator's change through the tools.
     */
    public function createScriptedDriver(): ConstructionDriver
    {
        return new ScriptedDriver($this->container->make(FeatureGeneratorManager::class));
    }

    /**
     * Create the driver that builds with a coding agent SDK in the workspace.
     */
    public function createSdkDriver(): ConstructionDriver
    {
        return $this->container->make(SdkDriver::class);
    }

    /**
     * Create the driver whose change is written by a worker outside our
     * boxes and handed back through its tools.
     */
    public function createWorkerDriver(): ConstructionDriver
    {
        return $this->container->make(WorkerDriver::class);
    }
}
