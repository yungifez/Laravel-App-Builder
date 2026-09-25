<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait BuildsInLocalWorkspaces
{
    /**
     * Build runs in real local workspaces under a temporary root.
     */
    protected function buildInLocalWorkspaces(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'builder-test-workspaces-'.Str::lower(Str::random(8));

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        config([
            'builder.construction.workspace_driver' => 'local',
            'builder.construction.setup' => [],
            'workspaces.drivers.local.root' => $root,
        ]);

        return $root;
    }
}
