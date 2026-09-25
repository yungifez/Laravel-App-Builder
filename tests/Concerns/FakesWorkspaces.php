<?php

namespace Tests\Concerns;

use App\Workspaces\WorkspaceManager;
use Tests\Fakes\FakeWorkspaceDriver;

trait FakesWorkspaces
{
    /**
     * Make "fake" the default workspace driver and return it.
     */
    protected function fakeWorkspaces(): FakeWorkspaceDriver
    {
        $driver = new FakeWorkspaceDriver;

        $this->app->make(WorkspaceManager::class)->extend('fake', fn () => $driver);

        config([
            'workspaces.default' => 'fake',
            'workspaces.drivers.fake' => ['image' => 'fake-image'],
        ]);

        return $driver;
    }
}
