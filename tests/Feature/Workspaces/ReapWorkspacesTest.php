<?php

namespace Tests\Feature\Workspaces;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\RunStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Run;
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

    public function test_an_idle_copy_stays_while_a_change_waits_for_the_owners_tool()
    {
        $driver = $this->fakeWorkspaces();
        config(['workspaces.lifetime.idle_minutes' => 30]);

        $waiting = Workspace::factory()->create(['last_activity_at' => now()->subMinutes(90)]);
        Run::factory()->implementing()->create(['driver' => 'worker', 'workspace_id' => $waiting->id]);

        $this->artisan('workspaces:reap')->expectsOutputToContain('Destroyed 0 workspace(s).')->assertSuccessful();

        $this->assertSame([], $driver->destroyed);
        $this->assertSame(WorkspaceStatus::Ready, $waiting->fresh()->status);
    }

    public function test_an_idle_copy_goes_when_our_coder_has_it_or_the_owners_tool_stopped()
    {
        $driver = $this->fakeWorkspaces();
        config(['workspaces.lifetime.idle_minutes' => 30]);

        $ours = Workspace::factory()->create(['last_activity_at' => now()->subMinutes(90)]);
        Run::factory()->implementing()->create(['driver' => 'sdk', 'workspace_id' => $ours->id]);
        $stopped = Workspace::factory()->create(['last_activity_at' => now()->subMinutes(90)]);
        Run::factory()->create(['driver' => 'worker', 'status' => RunStatus::Failed, 'workspace_id' => $stopped->id]);

        $this->artisan('workspaces:reap')->expectsOutputToContain('Destroyed 2 workspace(s).')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$ours->driver_id, $stopped->driver_id], $driver->destroyed);
    }

    public function test_a_copy_past_its_age_goes_even_while_the_owners_tool_has_the_change()
    {
        $driver = $this->fakeWorkspaces();

        $old = Workspace::factory()->create(['expires_at' => now()->subMinute()]);
        Run::factory()->implementing()->create(['driver' => 'worker', 'workspace_id' => $old->id]);

        $this->artisan('workspaces:reap')->expectsOutputToContain('Destroyed 1 workspace(s).')->assertSuccessful();

        $this->assertSame([$old->driver_id], $driver->destroyed);
    }

    public function test_reaping_is_scheduled()
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('workspaces:reap')
            ->assertSuccessful();
    }
}
