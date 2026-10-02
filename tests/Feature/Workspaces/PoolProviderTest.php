<?php

namespace Tests\Feature\Workspaces;

use App\Enums\PreviewStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Preview;
use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Boxes\BoxProviderManager;
use App\Workspaces\Boxes\Providers\PoolProvider;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
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

    public function test_a_full_runner_gets_no_new_workspace()
    {
        config(['workspaces.boxes.pool.max_workspaces' => 2]);
        $full = Runner::factory()->create(['name' => 'full']);
        $this->holding($full, 2);

        try {
            (new PoolProvider)->create($this->spec());
            $this->fail('A full runner got a new workspace.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('with room', $exception->getMessage());
        }

        $this->holding(Runner::factory()->create(['name' => 'roomy']), 1);

        $this->assertSame('roomy--workspace-new', (new PoolProvider)->create($this->spec()));
    }

    public function test_workspaces_still_opening_count_toward_a_runners_load()
    {
        config(['workspaces.boxes.pool.max_workspaces' => 2]);
        Runner::factory()->create(['name' => 'first']);
        Runner::factory()->create(['name' => 'second']);
        $pool = new PoolProvider;

        // None is recorded on a workspace yet, as while their boxes open.
        $placed = [$pool->create($this->spec('a')), $pool->create($this->spec('b')), $pool->create($this->spec('c')), $pool->create($this->spec('d'))];

        $this->assertEqualsCanonicalizing(['first--a', 'second--b', 'first--c', 'second--d'], $placed);
        $this->expectExceptionMessage('with room');
        $pool->create($this->spec('e'));
    }

    public function test_an_opened_box_counts_once_and_a_closed_one_not_at_all()
    {
        $runner = Runner::factory()->create(['name' => 'vm1']);
        $pool = new PoolProvider;
        $box = $pool->create($this->spec());
        $this->assertSame(1, $pool->load($runner));

        $workspace = Workspace::factory()->create(['driver' => 'runner', 'driver_id' => $box, 'status' => WorkspaceStatus::Ready]);
        $this->assertSame(1, $pool->load($runner));

        $workspace->update(['status' => WorkspaceStatus::Destroyed]);
        $this->assertSame(0, $pool->load($runner));
    }

    public function test_a_runner_whose_disk_is_nearly_full_gets_no_new_workspace()
    {
        config(['workspaces.boxes.pool.min_free_disk_mb' => 2048]);
        Runner::factory()->create(['name' => 'tight', 'disk_free_mb' => 1500]);
        $this->holding(Runner::factory()->create(['name' => 'roomy', 'disk_free_mb' => 30000]), 3);

        $this->assertSame('roomy--workspace-new', (new PoolProvider)->create($this->spec()));
    }

    public function test_a_runner_reports_its_free_disk_when_it_asks_for_work()
    {
        $token = 'vm1-token';
        Runner::factory()->create(['name' => 'vm1', 'token_hash' => Runner::hashToken($token), 'last_seen_at' => null]);
        config(['workspaces.drivers.runner.provider' => 'pool']);

        $this->withToken($token)->postJson(route('runner.commands.claim'), ['disk_free_mb' => 12345])->assertOk();

        $this->assertSame(12345, Runner::query()->sole()->disk_free_mb);
    }

    public function test_no_workspace_is_made_when_no_runner_is_online()
    {
        Runner::factory()->offline()->create();

        $this->expectException(RuntimeException::class);

        (new PoolProvider)->create($this->spec());
    }

    public function test_without_a_cloud_a_full_pool_refuses_at_once()
    {
        Sleep::fake();
        config(['workspaces.boxes.pool.max_workspaces' => 1, 'workspaces.machines.cloud' => null]);
        $this->holding(Runner::factory()->create(['name' => 'full']), 1);

        try {
            (new PoolProvider)->create($this->spec());
            $this->fail('A full pool without a cloud gave out a place.');
        } catch (RuntimeException) {
            Sleep::assertNeverSlept();
        }
    }

    public function test_on_a_cloud_a_workspace_waits_for_a_machine_and_counts_as_waiting()
    {
        config(['workspaces.boxes.pool.max_workspaces' => 1, 'workspaces.machines.cloud' => 'hetzner', 'workspaces.machines.boot_minutes' => 10]);
        $this->holding(Runner::factory()->create(['name' => 'full']), 1);
        $pool = new PoolProvider;
        $seenWaiting = null;

        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($pool, &$seenWaiting) {
            $seenWaiting ??= $pool->waiting();
            // A new machine comes up while the workspace waits.
            Runner::query()->firstOrCreate(['name' => 'fresh'], Runner::factory()->raw(['name' => 'fresh']));
        });

        $this->assertSame('fresh--workspace-new', $pool->create($this->spec()));
        $this->assertSame(1, $seenWaiting);
        $this->assertSame(0, $pool->waiting());
    }

    public function test_on_a_cloud_a_workspace_gives_up_when_no_machine_comes()
    {
        config(['workspaces.boxes.pool.max_workspaces' => 1, 'workspaces.machines.cloud' => 'hetzner', 'workspaces.machines.boot_minutes' => 10]);
        $this->holding(Runner::factory()->create(['name' => 'full', 'last_seen_at' => now()->addHour()]), 1);
        $pool = new PoolProvider;
        Sleep::fake(syncWithCarbon: true);

        try {
            $pool->create($this->spec());
            $this->fail('A full pool gave out a place.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No runner is online with room', $exception->getMessage());
        }

        Sleep::assertSleptTimes(120);
        $this->assertSame(0, $pool->waiting());
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

    public function test_a_machine_that_is_gone_for_good_is_removed_with_its_workspaces_closed()
    {
        $runner = Runner::factory()->create(['name' => 'vm1']);
        $this->holding($runner, 2);
        $preview = Preview::factory()->ready()->create(['workspace_id' => Workspace::query()->value('id')]);

        // A machine that still asks for work is not gone.
        $this->assertSame(1, Artisan::call('runners:remove', ['name' => 'vm1', '--gone' => true]));
        $this->assertSame(2, (new PoolProvider)->load($runner));

        $runner->update(['last_seen_at' => now()->subHour()]);

        $this->assertSame(0, Artisan::call('runners:remove', ['name' => 'vm1', '--gone' => true]));
        $this->assertStringContainsString('2 workspace(s) on it are closed', Artisan::output());
        $this->assertFalse(Runner::query()->whereKey($runner->id)->exists());
        $this->assertSame(0, Workspace::query()->whereNot('status', WorkspaceStatus::Destroyed)->count());
        $this->assertSame(PreviewStatus::Stopped, $preview->refresh()->status);
        $this->assertStringContainsString('This is our fault', (string) $preview->error);
    }

    public function test_the_pool_lists_each_machine_with_its_state_and_workspaces()
    {
        $this->artisan('runners:list')->expectsOutputToContain('There are no runner machines')->assertSuccessful();

        $this->holding(Runner::factory()->create(['name' => 'vm1', 'service_host' => '10.0.0.2']), 2);
        Runner::factory()->create(['name' => 'vm2', 'draining_at' => now()]);
        Runner::factory()->offline()->create(['name' => 'vm3', 'draining_at' => now()]);

        $this->artisan('runners:list')
            ->expectsTable(['Machine', 'State', 'Workspaces', 'Free disk', 'Last asked for work', 'Previews at'], [
                ['vm1', 'online', 2, '-', '0 seconds ago', '10.0.0.2'],
                ['vm2', 'draining', 0, '-', '0 seconds ago', Runner::query()->where('name', 'vm2')->value('service_host')],
                ['vm3', 'offline', 0, '-', '1 day ago', Runner::query()->where('name', 'vm3')->value('service_host')],
            ])
            ->assertSuccessful();
    }
}
