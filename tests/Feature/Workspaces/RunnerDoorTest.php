<?php

namespace Tests\Feature\Workspaces;

use App\Models\Runner;
use App\Workspaces\RunnerDoor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\InputStream;
use Tests\TestCase;

/**
 * The control plane reaches previews through a runner's preview door when
 * no private network joins them. These tests open the real door of the
 * real runner (tests/Fixtures/box-runner-door.mjs) and knock as the
 * control plane does.
 */
class RunnerDoorTest extends TestCase
{
    use RefreshDatabase;

    protected InvokedProcess $fixture;

    protected InputStream $input;

    /**
     * What the runner said in its hello, and its ports.
     *
     * @var array{hello: array{service_host: string, preview_door: array{port: int, pin: string, key: string}}, door_port: int, service_port: int}
     */
    protected array $door;

    protected Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::allowStrayRequests();
        $this->input = new InputStream;
        $this->fixture = Process::timeout(60)->input($this->input)->start(['node', base_path('tests/Fixtures/box-runner-door.mjs'), base_path('resources/box-runner/runner.mjs')]);
        $this->beforeApplicationDestroyed(function () {
            $this->input->close();
            $this->fixture->wait();
        });

        $line = '';
        $this->fixture->waitUntil(function (string $type, string $output) use (&$line) {
            if ($type === 'out') {
                $line .= $output;
            }

            return str_contains($line, "\n");
        });

        $this->assertStringContainsString("\n", $line, 'The runner did not open its door: '.$this->fixture->errorOutput());

        /** @var array{hello: array{service_host: string, preview_door: array{port: int, pin: string, key: string}}, door_port: int, service_port: int} $door */
        $door = json_decode(trim($line), true);
        $this->door = $door;
        $this->runner = Runner::factory()->create([
            'service_host' => $door['hello']['service_host'],
            'preview_door_port' => $door['hello']['preview_door']['port'],
            'preview_door_pin' => $door['hello']['preview_door']['pin'],
            'preview_door_key' => $door['hello']['preview_door']['key'],
        ]);
    }

    public function test_the_control_plane_reaches_a_preview_through_the_door(): void
    {
        $url = RunnerDoor::url('127.0.0.1', $this->door['door_port'], $this->door['service_port']);

        $answer = app(RunnerDoor::class)->prepare(Http::timeout(5), $url)->get("{$url}/teams?page=2");

        $this->assertSame(200, $answer->status());
        $this->assertSame('/teams?page=2', $answer->json('path'));
        // The key opens only the door; the owner's app never sees it.
        $this->assertNull($answer->json('key'));
        $this->assertSame('127.0.0.1', RunnerDoor::listenHost($url), 'Behind the door a preview listens only on the runner.');
    }

    public function test_the_door_stays_shut_without_its_key(): void
    {
        $url = RunnerDoor::url('127.0.0.1', $this->door['door_port'], $this->door['service_port']);

        $this->assertSame(401, Http::withOptions(['verify' => false])->timeout(5)->get("{$url}/")->status());
        $this->assertSame(401, Http::withOptions(['verify' => false])->withHeaders([RunnerDoor::KEY_HEADER => str_repeat('x', 43)])->timeout(5)->get("{$url}/")->status());
    }

    public function test_a_server_with_another_certificate_is_not_trusted(): void
    {
        $this->runner->update(['preview_door_pin' => base64_encode(random_bytes(32))]);
        $url = RunnerDoor::url('127.0.0.1', $this->door['door_port'], $this->door['service_port']);

        $this->expectException(ConnectionException::class);

        app(RunnerDoor::class)->prepare(Http::timeout(5), $url)->get("{$url}/");
    }

    public function test_a_port_the_runner_did_not_start_looks_like_a_stopped_preview(): void
    {
        $url = RunnerDoor::url('127.0.0.1', $this->door['door_port'], 1);

        $this->expectException(ConnectionException::class);

        app(RunnerDoor::class)->prepare(Http::timeout(5), $url)->get("{$url}/");
    }

    public function test_a_door_no_runner_has_any_more_looks_like_a_stopped_preview(): void
    {
        $this->runner->delete();
        $url = RunnerDoor::url('127.0.0.1', $this->door['door_port'], $this->door['service_port']);

        $this->expectException(ConnectionException::class);

        app(RunnerDoor::class)->prepare(Http::timeout(5), $url)->get("{$url}/");
    }
}
