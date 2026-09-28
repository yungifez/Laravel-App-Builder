<?php

namespace App\Workspaces\Boxes;

use App\Enums\BoxCommandStatus;
use App\Events\RunnerHasWork;
use App\Models\BoxCommand;
use Closure;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

/**
 * Sends commands to the runner in a box and waits for their results.
 *
 * Commands and results travel over HTTPS, which the runner starts: the box
 * accepts no connections. The socket only rings the runner's doorbell so it
 * fetches new work at once; when the socket is down, the runner still polls,
 * so nothing is lost, only slower.
 */
class BoxChannel
{
    public function __construct(protected BoxProviderManager $providers) {}

    /**
     * Queue a command for the box's runner.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $box, string $type, array $payload, int $timeoutSeconds): BoxCommand
    {
        $command = BoxCommand::create([
            'runner' => $this->providers->driver()->runnerFor($box),
            'box' => $box,
            'type' => $type,
            'payload' => $payload,
            'timeout_seconds' => $timeoutSeconds,
            'status' => BoxCommandStatus::Queued,
        ]);

        $this->ring($command->runner);

        return $command;
    }

    /**
     * Wait until the command ends. A runner that does not take the command
     * within "answer_seconds", or does not finish it within its timeout plus
     * "grace_seconds", has lost it.
     *
     * While waiting, "whileRunning" is called several times a second. When it
     * throws, the command is cancelled before the exception is passed on.
     *
     * @param  (Closure(): void)|null  $whileRunning
     */
    public function await(BoxCommand $command, ?Closure $whileRunning = null): BoxCommand
    {
        $answerBy = Date::now()->addSeconds((int) config('workspaces.drivers.runner.answer_seconds'));
        $finishBy = Date::now()->addSeconds($command->timeout_seconds + (int) config('workspaces.drivers.runner.grace_seconds'));

        // Most commands end within a few hundredths of a second, and each
        // rebuild after an edit runs several; look again soon at first, then
        // less often up to "poll_ms", so a long build does not load the
        // database.
        $pollMs = (int) config('workspaces.drivers.runner.poll_ms');
        $waitMs = min(20, $pollMs);

        while (true) {
            $command->refresh();

            if ($command->status->ended()) {
                return $command;
            }

            if ($whileRunning !== null) {
                try {
                    $whileRunning();
                } catch (Throwable $exception) {
                    $this->cancel($command);

                    throw $exception;
                }
            }

            if ($command->status === BoxCommandStatus::Queued && $answerBy->isPast()) {
                return $this->lose($command, 'No runner took the command.');
            }

            if ($finishBy->isPast()) {
                $this->cancel($command);

                return $this->lose($command, 'The runner did not finish the command in time.');
            }

            usleep($waitMs * 1000);
            $waitMs = min($waitMs * 2, $pollMs);
        }
    }

    /**
     * Send a command, wait for it and return its result. A command that
     * failed or was lost throws.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function call(string $box, string $type, array $payload, int $timeoutSeconds): array
    {
        $command = $this->await($this->send($box, $type, $payload, $timeoutSeconds));
        $result = $command->result ?? [];

        if ($command->status === BoxCommandStatus::Lost || ($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException("The workspace could not {$type}: ".trim((string) ($result['error_output'] ?? '')));
        }

        return $result;
    }

    /**
     * Ask the runner to stop a command. A command it has not taken yet ends
     * here and is never run.
     */
    public function cancel(BoxCommand $command): void
    {
        $now = Date::now();

        $unclaimed = BoxCommand::whereKey($command->id)
            ->where('status', BoxCommandStatus::Queued)
            ->update([
                'status' => BoxCommandStatus::Finished,
                'payload' => null,
                'result' => json_encode(['exit_code' => 143, 'output' => '', 'error_output' => 'Cancelled before it started.', 'timed_out' => false, 'duration_ms' => 0]),
                'cancel_requested_at' => $now,
                'finished_at' => $now,
            ]);

        if ($unclaimed === 0) {
            BoxCommand::whereKey($command->id)->whereNull('cancel_requested_at')->update(['cancel_requested_at' => $now]);
            $this->ring($command->runner);
        }
    }

    /**
     * Record that the runner lost the command, unless it has just finished.
     */
    protected function lose(BoxCommand $command, string $reason): BoxCommand
    {
        BoxCommand::whereKey($command->id)
            ->whereIn('status', [BoxCommandStatus::Queued, BoxCommandStatus::Claimed])
            ->update([
                'status' => BoxCommandStatus::Lost,
                'payload' => null,
                'result' => json_encode(['exit_code' => null, 'output' => '', 'error_output' => $reason, 'timed_out' => true, 'duration_ms' => 0]),
                'finished_at' => Date::now(),
            ]);

        return $command->refresh();
    }

    /**
     * Ring the runner's doorbell. A missed ring only delays the work until
     * the runner's next poll, so it never fails the command.
     */
    protected function ring(string $runner): void
    {
        // broadcast() would send only when its result is destroyed, outside
        // this rescue; queue() sends a ShouldBroadcastNow event right here.
        rescue(fn () => Broadcast::queue(new RunnerHasWork($runner)), report: false);
    }
}
