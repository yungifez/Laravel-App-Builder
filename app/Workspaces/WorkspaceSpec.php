<?php

namespace App\Workspaces;

class WorkspaceSpec
{
    public function __construct(
        public string $name,
        public string $image,
        public float $cpus,
        public int $memoryMb,
        public int $pids,
    ) {}

    /**
     * Build a spec from the configured workspace size and the driver's image.
     */
    public static function fromConfig(string $name, string $image): self
    {
        return new self(
            name: $name,
            image: $image,
            cpus: (float) config('workspaces.size.cpus'),
            memoryMb: (int) config('workspaces.size.memory_mb'),
            pids: (int) config('workspaces.size.pids'),
        );
    }
}
