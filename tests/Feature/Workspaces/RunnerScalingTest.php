<?php

namespace Tests\Feature\Workspaces;

use App\Actions\Runners\ScaleRunnerPool;
use App\Enums\WorkspaceStatus;
use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Machines\RunnerBootScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class RunnerScalingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The machines the fake Hetzner holds, by id, with their runner names.
     *
     * @var array<string, string>
     */
    protected array $servers = [];

    /** @var list<string> */
    protected array $deleted = [];

    /**
     * Why the fake Hetzner refuses to start a machine, if it does.
     */
    protected ?string $refuse = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workspaces.machines.cloud' => 'hetzner',
            'workspaces.machines.min' => 0,
            'workspaces.machines.max' => 3,
            'workspaces.machines.spare_workspaces' => 2,
            'workspaces.machines.empty_minutes' => 20,
            'workspaces.machines.boot_minutes' => 10,
            'workspaces.machines.boot_retry_minutes' => 30,
            'workspaces.machines.box_image' => 'registry.example.test/builder-box:1',
            'workspaces.machines.control_plane_url' => 'https://builder.example.test',
            'workspaces.machines.clouds.hetzner.token' => 'hetzner-test-token',
            'workspaces.boxes.pool.max_workspaces' => 4,
        ]);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/v1/servers') {
                return Http::response(['servers' => collect($this->servers)->map(fn (string $runner, string $id) => ['id' => (int) $id, 'labels' => ['builder-pool' => 'builder', 'builder-runner' => $runner]])->values()->all(), 'meta' => ['pagination' => ['next_page' => null]]]);
            }

            if ($request->method() === 'POST' && $path === '/v1/servers' && $this->refuse !== null) {
                return Http::response(['error' => ['code' => 'resource_limit_exceeded', 'message' => $this->refuse]], 403);
            }

            if ($request->method() === 'POST' && $path === '/v1/servers') {
                $id = (string) (100 + count($this->servers) + count($this->deleted));
                $this->servers[$id] = $request['labels']['builder-runner'];

                return Http::response(['server' => ['id' => (int) $id]], 201);
            }

            if ($request->method() === 'DELETE' && preg_match('#^/v1/servers/(\d+)$#', $path, $match)) {
                $this->deleted[] = $match[1];
                unset($this->servers[$match[1]]);

                return Http::response(['action' => ['id' => 1]]);
            }

            return Http::response(['error' => ['message' => 'Unexpected request']], 500);
        });
    }

    public function test_a_pool_without_a_cloud_is_left_alone()
    {
        config(['workspaces.machines.cloud' => null]);

        $this->artisan('runners:scale')->expectsOutputToContain('added by hand')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_machine_starts_on_hetzner_when_the_pool_runs_out_of_room()
    {
        $this->artisan('runners:scale')->expectsOutputToContain('Started machine')->assertSuccessful();

        $runner = Runner::query()->sole();
        $this->assertSame('hetzner', $runner->cloud);
        $this->assertSame('100', $runner->cloud_id);
        $this->assertNull($runner->last_seen_at);

        Http::assertSent(function (Request $request) use ($runner) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $this->assertSame('Bearer hetzner-test-token', $request->header('Authorization')[0]);
            $this->assertSame('cx33', $request['server_type']);
            $this->assertSame("builder-{$runner->name}", $request['name']);
            $this->assertSame(['builder-pool' => 'builder', 'builder-runner' => $runner->name], $request['labels']);
            $this->assertStringContainsString('RUNNER_URL=https://builder.example.test', $request['user_data']);
            $this->assertMatchesRegularExpression('/RUNNER_TOKEN=(\S{64})/', $request['user_data']);
            $this->assertStringContainsString('registry.example.test/builder-box:1', $request['user_data']);
            // The token is kept only as a hash.
            preg_match('/RUNNER_TOKEN=(\S{64})/', $request['user_data'], $token);
            $this->assertSame(Runner::hashToken($token[1]), $runner->token_hash);

            return true;
        });

        // The machine still starting holds the free places, so no second
        // one starts.
        $this->artisan('runners:scale')->expectsOutputToContain('right size')->assertSuccessful();
        $this->assertSame(1, Runner::query()->count());

        $this->artisan('runners:list')->expectsOutputToContain('starting')->assertSuccessful();
    }

    public function test_workspaces_waiting_for_room_make_the_pool_start_a_machine()
    {
        // Two free places, as many as wanted, so no machine would start.
        $this->onCloud('half', '7', holding: 2);
        $this->artisan('runners:scale')->expectsOutputToContain('right size')->assertSuccessful();

        Cache::put('workspaces:pool:waiting', ['a' => now()->addMinutes(5)->getTimestamp()]);

        $this->artisan('runners:scale')->expectsOutputToContain('Started machine')->assertSuccessful();
    }

    public function test_machines_on_an_older_box_image_are_replaced_one_at_a_time()
    {
        $busy = $this->onCloud('busy', '7', holding: 2);
        $empty = $this->onCloud('empty', '8', holding: 0);
        $current = $this->onCloud('current', '9', holding: 0);
        $busy->forceFill(['box_image' => 'registry.example.test/builder-box:0', 'created_at' => now()->subDay()])->save();
        $empty->update(['box_image' => 'registry.example.test/builder-box:0']);
        $current->update(['box_image' => 'registry.example.test/builder-box:1']);

        // The oldest goes first, and its work finishes before it is deleted.
        $this->artisan('runners:scale')->expectsOutputToContain('Draining machine [busy]')->assertSuccessful();
        $this->assertNotNull($busy->refresh()->draining_at);
        $this->assertNull($empty->refresh()->draining_at);

        // While one drains, no other is taken out.
        $this->artisan('runners:scale')->assertSuccessful();
        $this->assertNull($empty->refresh()->draining_at);

        Workspace::query()->where('driver_id', 'like', 'busy--%')->update(['status' => WorkspaceStatus::Destroyed]);
        // Once it is gone, the next one is taken out in the same pass.
        $this->artisan('runners:scale')
            ->expectsOutputToContain('Deleted machine [busy]: it finished draining')
            ->expectsOutputToContain('Deleted machine [empty]: it ran an older box image')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['7', '8'], $this->deleted);
        $this->assertTrue(Runner::query()->whereKey($current->id)->exists());
        $this->assertSame('registry.example.test/builder-box:1', Runner::query()->latest('id')->first()?->box_image);
    }

    public function test_machines_added_by_hand_are_never_replaced_for_their_image()
    {
        $this->onCloud('busy', '7', holding: 3)->update(['box_image' => null]);

        $this->artisan('runners:scale')->assertSuccessful();

        $this->assertSame([], $this->deleted);
    }

    public function test_no_more_machines_start_than_the_most_allowed()
    {
        config(['workspaces.machines.max' => 1]);
        $this->onCloud('busy', '7', holding: 4);

        $this->artisan('runners:scale')->assertSuccessful();

        $this->assertSame(1, Runner::query()->count());
    }

    public function test_a_machine_that_held_nothing_for_a_while_is_deleted_when_the_pool_has_room_without_it()
    {
        $this->onCloud('roomy', '7', holding: 1);
        $empty = $this->onCloud('empty', '8', holding: 0, createdAt: now()->subMinutes(55));

        $this->artisan('runners:scale')->expectsOutputToContain('Deleted machine [empty]')->assertSuccessful();

        $this->assertSame(['8'], $this->deleted);
        $this->assertFalse(Runner::query()->whereKey($empty->id)->exists());
    }

    public function test_an_empty_machine_is_kept_until_the_hour_paid_for_it_is_nearly_over()
    {
        $this->onCloud('roomy', '7', holding: 1);
        $empty = $this->onCloud('empty', '8', holding: 0, createdAt: now()->subMinutes(90));

        // Half of its second hour is paid for and left.
        $this->artisan('runners:scale')->assertSuccessful();
        $this->assertSame([], $this->deleted);

        $this->travel(21)->minutes();
        $this->artisan('runners:scale')->expectsOutputToContain('Deleted machine [empty]')->assertSuccessful();
        $this->assertFalse(Runner::query()->whereKey($empty->id)->exists());
    }

    public function test_a_cloud_that_bills_by_the_second_deletes_an_empty_machine_at_once()
    {
        config(['workspaces.machines.clouds.hetzner.billing_minutes' => 0]);
        $this->onCloud('roomy', '7', holding: 1);
        $this->onCloud('empty', '8', holding: 0, createdAt: now()->subMinutes(90));

        $this->artisan('runners:scale')->expectsOutputToContain('Deleted machine [empty]')->assertSuccessful();
    }

    public function test_a_machine_is_kept_when_the_pool_needs_its_room_or_its_least_number()
    {
        $this->onCloud('roomy', '7', holding: 3, createdAt: now()->subHour());
        $this->onCloud('empty', '8', holding: 0, createdAt: now()->subMinutes(55));

        // Without it, one place would be free, and two are wanted.
        $this->artisan('runners:scale')->assertSuccessful();
        $this->assertSame([], $this->deleted);

        config(['workspaces.machines.min' => 2, 'workspaces.machines.spare_workspaces' => 0]);
        $this->artisan('runners:scale')->assertSuccessful();
        $this->assertSame([], $this->deleted);
    }

    public function test_a_machine_that_recently_held_a_workspace_is_kept_a_while()
    {
        $this->onCloud('roomy', '7', holding: 1);
        $recent = $this->onCloud('recent', '8', holding: 0, createdAt: now()->subMinutes(55));
        Workspace::factory()->create(['driver' => 'runner', 'driver_id' => 'recent--workspace-old', 'status' => WorkspaceStatus::Destroyed]);

        $this->artisan('runners:scale')->assertSuccessful();

        $this->assertTrue(Runner::query()->whereKey($recent->id)->exists());
    }

    public function test_a_machine_whose_runner_never_answered_or_went_silent_is_deleted_with_its_workspaces_closed()
    {
        $this->onCloud('roomy', '7', holding: 0);
        $neverBooted = $this->onCloud('never', '8', holding: 0, createdAt: now()->subMinutes(30), lastSeen: false);
        $silent = $this->onCloud('silent', '9', holding: 2, createdAt: now()->subDay());
        $silent->update(['last_seen_at' => now()->subMinutes(15)]);

        $this->artisan('runners:scale')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['8', '9'], $this->deleted);
        $this->assertFalse(Runner::query()->whereKey($neverBooted->id)->exists());
        $this->assertFalse(Runner::query()->whereKey($silent->id)->exists());
        $this->assertSame(0, Workspace::query()->where('driver_id', 'like', 'silent--%')->whereNot('status', WorkspaceStatus::Destroyed)->count());
    }

    public function test_no_machine_starts_for_a_while_after_a_new_one_never_answered()
    {
        Exceptions::fake();
        $this->onCloud('never', '8', holding: 0, createdAt: now()->subMinutes(11), lastSeen: false);

        // The machine is deleted, and the pool, now empty, does not start
        // another one that would fail the same way.
        $this->artisan('runners:scale')->expectsOutputToContain('never answered')->assertSuccessful();
        $this->assertSame(['8'], $this->deleted);
        $this->assertSame(0, Runner::query()->count());
        Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), 'never asked for work'));

        $this->travel(29)->minutes();
        $this->artisan('runners:scale')->expectsOutputToContain('Not starting machines')->assertSuccessful();
        $this->assertSame(0, Runner::query()->count());

        $this->travel(2)->minutes();
        $this->artisan('runners:scale')->expectsOutputToContain('Started machine')->assertSuccessful();
        $this->assertSame(1, Runner::query()->count());
    }

    public function test_a_cloud_that_refuses_a_machine_is_not_asked_again_for_a_while()
    {
        Exceptions::fake();
        $this->refuse = 'server limit reached';

        $this->artisan('runners:scale')->expectsOutputToContain('Could not start a machine: Hetzner could not start a machine: server limit reached')->assertSuccessful();
        $this->assertSame(0, Runner::query()->count());
        Exceptions::assertReported(RuntimeException::class);

        $this->artisan('runners:scale')->expectsOutputToContain('The cloud would not start a machine')->assertSuccessful();
        Http::assertSentCount(3);

        $this->refuse = null;
        $this->travel(31)->minutes();
        $this->artisan('runners:scale')->expectsOutputToContain('Started machine')->assertSuccessful();
    }

    public function test_the_pause_holds_when_the_cache_gives_the_time_back_as_text()
    {
        // Redis keeps a number as text.
        Cache::put(ScaleRunnerPool::PAUSED_UNTIL, (string) now()->addMinutes(5)->getTimestamp());

        $this->artisan('runners:scale')->expectsOutputToContain('Not starting machines')->assertSuccessful();

        $this->assertSame(0, Runner::query()->count());
    }

    public function test_a_machine_that_went_silent_after_working_does_not_stop_new_ones()
    {
        $silent = $this->onCloud('silent', '9', holding: 0, createdAt: now()->subDay());
        $silent->update(['last_seen_at' => now()->subMinutes(15)]);

        $this->artisan('runners:scale')->assertSuccessful();

        $this->assertSame(['9'], $this->deleted);
        $this->assertSame(1, Runner::query()->count(), 'A new machine starts in its place.');
    }

    public function test_a_stray_machine_on_the_cloud_is_deleted_and_a_runner_waiting_for_its_id_gets_it()
    {
        $this->onCloud('roomy', '7', holding: 0);
        $this->servers['50'] = 'nobody';
        $waiting = Runner::factory()->create(['name' => 'waiting', 'cloud' => 'hetzner', 'cloud_id' => null, 'last_seen_at' => null]);
        $this->servers['51'] = 'waiting';

        $this->artisan('runners:scale')->assertSuccessful();

        $this->assertSame(['50'], $this->deleted);
        $this->assertSame('51', $waiting->refresh()->cloud_id);
    }

    public function test_removing_a_cloud_runner_deletes_its_machine_and_a_hand_added_one_touches_no_cloud()
    {
        $this->onCloud('vm1', '7', holding: 0);
        Runner::factory()->create(['name' => 'byhand']);

        $this->assertSame(0, Artisan::call('runners:remove', ['name' => 'vm1']));
        $this->assertSame(0, Artisan::call('runners:remove', ['name' => 'byhand']));

        $this->assertSame(['7'], $this->deleted);
        $this->assertSame(0, Runner::query()->count());
    }

    public function test_the_boot_script_sets_the_runner_up_as_a_service_and_refuses_unsafe_values()
    {
        $script = (new RunnerBootScript)->make('https://builder.example.test', str_repeat('a', 64), 'registry.example.test/builder-box:1', 'hostname -I');

        $this->assertStringStartsWith("#!/bin/bash\n", $script);
        $this->assertStringContainsString('install -m 600 /dev/null /etc/builder-runner.env', $script);
        $this->assertStringContainsString('--cap-add NET_ADMIN', $script);
        $this->assertStringContainsString('systemctl enable --now builder-runner', $script);

        $this->expectException(InvalidArgumentException::class);
        (new RunnerBootScript)->make("https://x.test\nrm -rf /", 'token', 'image', 'hostname -I');
    }

    /**
     * A runner on a cloud machine that is online and holds workspaces.
     */
    protected function onCloud(string $name, string $id, int $holding, mixed $createdAt = null, bool $lastSeen = true): Runner
    {
        $runner = Runner::factory()->create(['name' => $name, 'cloud' => 'hetzner', 'cloud_id' => $id, 'last_seen_at' => $lastSeen ? now() : null]);

        if ($createdAt !== null) {
            $runner->forceFill(['created_at' => $createdAt])->save();
        }

        $this->servers[$id] = $name;

        Workspace::factory()->count($holding)->sequence(fn ($sequence) => ['driver' => 'runner', 'driver_id' => "{$name}--workspace-{$sequence->index}", 'status' => WorkspaceStatus::Ready])->create();

        return $runner;
    }
}
