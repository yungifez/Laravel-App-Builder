<?php

namespace Tests\Feature\Workspaces;

use App\Actions\Workspaces\ProvisionWorkspace;
use App\Enums\WorkspaceStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\FakesWorkspaces;
use Tests\TestCase;

class ProvisionWorkspaceTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    public function test_it_starts_a_workspace_with_the_configured_size()
    {
        $driver = $this->fakeWorkspaces();
        config([
            'workspaces.size' => ['cpus' => 1.5, 'memory_mb' => 1024, 'pids' => 256],
            'workspaces.lifetime.max_minutes' => 60,
        ]);
        $this->freezeSecond();
        $owner = User::factory()->create();

        $workspace = app(ProvisionWorkspace::class)->handle($owner);

        $this->assertSame(WorkspaceStatus::Ready, $workspace->status);
        $this->assertSame('fake-1', $workspace->driver_id);
        $this->assertSame('fake', $workspace->driver);
        $this->assertSame('fake-image', $workspace->image);
        $this->assertTrue($workspace->owner->is($owner));
        $this->assertTrue($workspace->expires_at->equalTo(now()->addHour()));

        $spec = $driver->created[0];
        $this->assertSame(1.5, $spec->cpus);
        $this->assertSame(1024, $spec->memoryMb);
        $this->assertSame(256, $spec->pids);
        $this->assertStringStartsWith('workspace-', $spec->name);
    }

    public function test_a_workspace_that_fails_to_start_is_marked_failed()
    {
        $driver = $this->fakeWorkspaces();
        $driver->failCreate = true;
        $owner = User::factory()->create();

        try {
            app(ProvisionWorkspace::class)->handle($owner);
            $this->fail('Expected the driver failure to be rethrown.');
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(WorkspaceStatus::Failed, $owner->workspaces()->sole()->status);
    }
}
