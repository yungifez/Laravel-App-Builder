<?php

namespace Tests\Feature\Workspaces;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\TestCase;

class ReapWorkspacesTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    public function test_destroying_a_workspace_removes_it_from_the_driver()
    {
        $driver = $this->fakeWorkspaces();
        $workspace = Workspace::factory()->create();

        app(DestroyWorkspace::class)->handle($workspace);

        $this->assertSame([$workspace->driver_id], $driver->destroyed);
        $this->assertSame(WorkspaceStatus::Destroyed, $workspace->fresh()->status);
        $this->assertNotNull($workspace->fresh()->destroyed_at);
    }

    public function test_idle_and_expired_workspaces_are_reaped_and_active_ones_are_kept()
    {
        $driver = $this->fakeWorkspaces();
        config(['workspaces.lifetime.idle_minutes' => 30]);

        $idle = Workspace::factory()->create(['last_activity_at' => now()->subMinutes(31)]);
        $expired = Workspace::factory()->create(['expires_at' => now()->subMinute()]);
        $active = Workspace::factory()->create(['last_activity_at' => now()->subMinutes(5)]);
        $alreadyDestroyed = Workspace::factory()->create([
            'status' => WorkspaceStatus::Destroyed,
            'last_activity_at' => now()->subDay(),
        ]);

        $this->artisan('workspaces:reap')
            ->expectsOutputToContain('Destroyed 2 workspace(s).')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$idle->driver_id, $expired->driver_id], $driver->destroyed);
        $this->assertSame(WorkspaceStatus::Ready, $active->fresh()->status);
        $this->assertSame(WorkspaceStatus::Destroyed, $alreadyDestroyed->fresh()->status);
    }

    public function test_reaping_is_scheduled()
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('workspaces:reap')
            ->assertSuccessful();
    }
}
