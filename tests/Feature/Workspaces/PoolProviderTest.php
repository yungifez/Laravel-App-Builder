<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Boxes\BoxProviderManager;
use App\Workspaces\Boxes\Providers\PoolProvider;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class PoolProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workspaces.boxes.static' => ['runner' => 'local', 'token' => 'static-token', 'service_host' => 'runner'],
            'workspaces.boxes.pool' => ['online_seconds' => 120],
        ]);
    }

    protected function spec(string $name = 'workspace-new'): WorkspaceSpec
    {
        return WorkspaceSpec::fromConfig($name, 'box');
    }

    protected function holding(Runner $runner, int $count, WorkspaceStatus $status = WorkspaceStatus::Ready): void
    {
        Workspace::factory()->count($count)->sequence(fn ($sequence) => ['driver' => 'runner', 'driver_id' => "{$runner->name}--workspace-{$status->value}-{$sequence->index}", 'status' => $status])->create();
    }

    public function test_a_new_workspace_goes_to_the_online_runner_holding_the_fewest()
    {
        $busy = Runner::factory()->create(['name' => 'busy']);
        $quiet = Runner::factory()->create(['name' => 'quiet']);
        // An offline runner gets no new workspace, even holding none.
        Runner::factory()->offline()->create(['name' => 'gone']);
        $this->holding($busy, 3);
        $this->holding($quiet, 1);
        // Destroyed workspaces no longer count.
        $this->holding($quiet, 5, WorkspaceStatus::Destroyed);

        $box = (new PoolProvider)->create($this->spec());

        $this->assertSame('quiet--workspace-new', $box);
        $this->assertSame('quiet', (new PoolProvider)->runnerFor($box));
    }

    public function test_no_workspace_is_made_when_no_runner_is_online()
    {
        Runner::factory()->offline()->create();

        $this->expectException(RuntimeException::class);

        (new PoolProvider)->create($this->spec());
    }

    public function test_previews_are_reached_at_the_address_their_runner_reported()
    {
        Runner::factory()->create(['name' => 'vm1', 'service_host' => '10.0.0.5']);
        Runner::factory()->create(['name' => 'vm2', 'service_host' => null]);

        $this->assertSame('http://10.0.0.5:20001', (new PoolProvider)->serviceUrl('vm1--workspace-a', 20001));

        $this->expectException(RuntimeException::class);
        (new PoolProvider)->serviceUrl('vm2--workspace-b', 20001);
    }

    public function test_a_pool_runner_gets_in_with_its_own_token_and_says_where_it_is_reached()
    {
        config(['workspaces.drivers.runner.provider' => 'pool']);
        $runner = Runner::factory()->create(['name' => 'vm1', 'token_hash' => Runner::hashToken('vm1-token'), 'service_host' => null, 'last_seen_at' => null]);

        $this->assertSame('vm1', app(BoxProviderManager::class)->authenticate('vm1-token'));
        $this->assertSame('local', app(BoxProviderManager::class)->authenticate('static-token'), 'The static runner still gets in.');
        $this->assertNull(app(BoxProviderManager::class)->authenticate('wrong'));

        $this->withToken('vm1-token')->postJson('/api/runner/hello', ['service_host' => '10.0.0.5'])
            ->assertOk()
            ->assertJson(['runner' => 'vm1']);

        $runner->refresh();
        $this->assertSame('10.0.0.5', $runner->service_host);
        $this->assertNotNull($runner->last_seen_at);

        $this->withToken('vm1-token')->postJson('/api/runner/hello', ['service_host' => 'http://evil/'])->assertUnprocessable();
    }

    public function test_asking_for_work_keeps_a_pool_runner_online()
    {
        $runner = Runner::factory()->offline()->create(['token_hash' => Runner::hashToken('vm-token')]);

        $this->withToken('vm-token')->postJson('/api/runner/commands/claim')->assertOk();

        $this->assertTrue(Runner::query()->online()->whereKey($runner->id)->exists());
    }

    public function test_a_runner_is_added_with_a_token_shown_once_and_kept_only_as_a_hash()
    {
        $this->assertSame(0, Artisan::call('runners:add', ['name' => 'vm1']));
        $output = Artisan::output();

        preg_match('/RUNNER_TOKEN=(\S+)/', $output, $match);
        $runner = Runner::query()->sole();

        $this->assertSame('vm1', $runner->name);
        $this->assertSame(Runner::hashToken($match[1]), $runner->token_hash);
        $this->assertStringNotContainsString($match[1], json_encode($runner->toArray()) ?: '');

        // Box names are "{runner}--{workspace}", so a name holds no dashes.
        $this->assertSame(1, Artisan::call('runners:add', ['name' => 'vm-2']));
        $this->assertSame(1, Artisan::call('runners:add', ['name' => 'vm1']));
    }

    public function test_a_runner_gets_a_new_token_and_the_old_one_stops_working()
    {
        Artisan::call('runners:add', ['name' => 'vm1']);
        preg_match('/RUNNER_TOKEN=(\S+)/', Artisan::output(), $old);

        $this->assertSame(0, Artisan::call('runners:token', ['name' => 'vm1']));
        preg_match('/RUNNER_TOKEN=(\S+)/', Artisan::output(), $new);

        $this->assertNotSame($old[1], $new[1]);
        $this->assertNull((new PoolProvider)->authenticate($old[1]));
        $this->assertSame('vm1', (new PoolProvider)->authenticate($new[1]));
        $this->assertSame(1, Artisan::call('runners:token', ['name' => 'nobody']));
    }

    public function test_a_runner_drains_and_is_removed_once_it_holds_no_workspaces()
    {
        $runner = Runner::factory()->create(['name' => 'vm1']);
        $this->holding($runner, 1);
        $this->holding($runner, 2, WorkspaceStatus::Destroyed);

        $this->assertSame(1, Artisan::call('runners:remove', ['name' => 'vm1']));
        $this->assertStringContainsString('still holds 1', Artisan::output());
        $this->assertNotNull($runner->refresh()->draining_at);

        // It keeps its workspace, but new ones go elsewhere, or nowhere.
        try {
            (new PoolProvider)->create($this->spec());
            $this->fail('A draining runner got a new workspace.');
        } catch (RuntimeException) {
        }
        Runner::factory()->create(['name' => 'vm2']);
        $this->assertSame('vm2--workspace-new', (new PoolProvider)->create($this->spec()));

        Workspace::query()->update(['status' => WorkspaceStatus::Destroyed]);

        $this->assertSame(0, Artisan::call('runners:remove', ['name' => 'vm1']));
        $this->assertFalse(Runner::query()->whereKey($runner->id)->exists());
        $this->assertSame(1, Artisan::call('runners:remove', ['name' => 'vm1']));
    }
}
