<?php

namespace Tests\Fakes;

use App\Actions\Workspaces\ClaimBoxCommands;
use App\Actions\Workspaces\FinishBoxCommand;
use App\Models\BoxCommand;
use Closure;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;

/**
 * A box runner behind the doorbell: each ring claims the runner's work as the
 * real runner would, and answers each command with the handler for its type.
 * A handler that returns null leaves its command running.
 */
class FakeBoxRunner extends Broadcaster
{
    /** @var array<string, Closure(array<string, mixed>, BoxCommand): (array<string, mixed>|null)> */
    public array $handlers = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<array{type: string, box: string, payload: array<string, mixed>}> */
    public array $received = [];

    public function __construct(public string $runner = 'local') {}

    /**
     * @param  Closure(array<string, mixed>, BoxCommand): (array<string, mixed>|null)  $handler
     */
    public function on(string $type, Closure $handler): self
    {
        $this->handlers[$type] = $handler;

        return $this;
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $work = app(ClaimBoxCommands::class)->handle($this->runner);

        array_push($this->cancelled, ...$work['cancel']);

        foreach ($work['commands'] as $claimed) {
            $this->received[] = ['type' => $claimed['type'], 'box' => $claimed['box'], 'payload' => $claimed['payload']];
            $handler = $this->handlers[$claimed['type']] ?? fn () => ['exit_code' => 0, 'output' => '', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 1];
            $result = $handler($claimed['payload'], BoxCommand::findOrFail($claimed['id']));

            if ($result !== null) {
                app(FinishBoxCommand::class)->handle(BoxCommand::findOrFail($claimed['id']), $result);
            }
        }
    }

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return null;
    }
}
