<?php

namespace Tests\Feature\Workspaces;

use App\Enums\BoxCommandStatus;
use App\Models\BoxCommand;
use App\Workspaces\Boxes\BoxProviderManager;
use App\Workspaces\Boxes\Providers\DockerProvider;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DockerProviderTest extends TestCase
{
    use RefreshDatabase;

    protected DockerProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workspaces.drivers.runner.provider' => 'docker',
            'workspaces.boxes.static' => ['runner' => 'local', 'token' => 'static-token', 'service_host' => 'runner'],
            'workspaces.boxes.docker' => ['url' => 'http://boxes:8090', 'token' => 'boxes-token', 'control_plane_url' => 'http://laravel.test'],
        ]);

        /** @var DockerProvider $provider */
        $provider = $this->app->make(BoxProviderManager::class)->driver('docker');
        $this->provider = $provider;
    }

    public function test_a_box_is_made_with_its_limits_and_a_token_of_its_own()
    {
        Http::fake(['boxes:8090/boxes' => Http::response(['name' => 'workspace-1'], 201)]);

        $box = $this->provider->create(new WorkspaceSpec('workspace-1', 'box', 1.5, 1024, 256));

        $this->assertSame('workspace-1', $box);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://boxes:8090/boxes'
            && $request->hasHeader('Authorization', 'Bearer boxes-token')
            && $request['name'] === 'workspace-1'
            && $request['cpus'] === 1.5
            && $request['memory_mb'] === 1024
            && $request['pids'] === 256
            && $request['env'] === ['RUNNER_URL' => 'http://laravel.test', 'RUNNER_TOKEN' => $this->provider->tokenFor('workspace-1')]);
    }

    public function test_a_box_token_opens_only_its_own_runner()
    {
        $token = $this->provider->tokenFor('workspace-1');

        $this->assertSame('workspace-1', $this->provider->authenticate($token));
        $this->assertNull($this->provider->authenticate('workspace-2.'.explode('.', $token)[1]));
        $this->assertNull($this->provider->authenticate('workspace-1.forged'));
        $this->assertNull($this->provider->authenticate('workspace-1'));
    }

    public function test_a_box_runner_claims_only_its_own_commands()
    {
        $mine = BoxCommand::create(['runner' => 'workspace-1', 'box' => 'workspace-1', 'type' => 'exec', 'payload' => [], 'timeout_seconds' => 30, 'status' => BoxCommandStatus::Queued]);
        BoxCommand::create(['runner' => 'workspace-2', 'box' => 'workspace-2', 'type' => 'exec', 'payload' => [], 'timeout_seconds' => 30, 'status' => BoxCommandStatus::Queued]);

        $this->withToken($this->provider->tokenFor('workspace-1'))
            ->postJson('/api/runner/commands/claim')
            ->assertOk()
            ->assertJsonCount(1, 'commands')
            ->assertJsonPath('commands.0.id', $mine->id);
    }

    public function test_runners_of_another_configured_provider_still_get_in_after_a_switch()
    {
        $this->withToken('static-token')->postJson('/api/runner/hello')->assertOk()->assertJsonPath('runner', 'local');
    }

    public function test_services_are_reached_by_box_name_and_destroying_asks_the_box_service()
    {
        Http::fake(['boxes:8090/boxes/workspace-1' => Http::response(null, 204)]);

        $this->assertSame('http://box-workspace-1:8000', $this->provider->serviceUrl('workspace-1', 8000));

        $this->provider->destroy('workspace-1');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'http://boxes:8090/boxes/workspace-1');
    }
}
