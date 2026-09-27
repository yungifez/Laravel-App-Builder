<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Publishing\PublishingHostManager;
use App\Publishing\ReleaseProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ConfirmDeployment implements ShouldQueue
{
    use Queueable;

    /**
     * Each check is its own job; a later one is queued while time is left.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Deployment $deployment) {}

    /**
     * Check that the host has the new version live and that the app answers
     * at its address. The host can still fail to build, migrate or start
     * it, so handing it over never counts as online.
     */
    public function handle(PublishingHostManager $hosts): void
    {
        $deployment = $this->deployment->fresh();
        $address = rtrim((string) $deployment?->project->live_url, '/');

        if ($deployment?->status !== DeploymentStatus::Confirming || $address === '') {
            return;
        }

        $progress = $hosts->driver($deployment->host ?? $deployment->project->publishingHost())->progress($deployment);

        if ($progress === ReleaseProgress::Failed) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'error' => __('Your hosting could not start the new version, so your app online has not changed.'),
                'finished_at' => now(),
            ]);

            return;
        }

        // The old version still answers while the host builds the new one,
        // so the address says nothing until the host is done.
        $health = $progress === ReleaseProgress::Pending ? [] : array_map(fn (string $path) => $this->check($address, $path), (array) config('builder.publishing.confirm.paths'));

        if ($health !== [] && ! in_array(false, array_column($health, 'passed'), true)) {
            $deployment->update(['status' => DeploymentStatus::Published, 'health' => $health, 'confirmed_at' => now(), 'finished_at' => now()]);

            return;
        }

        if ($deployment->pushed_at?->addSeconds((int) config('builder.publishing.confirm.confirm_seconds'))->isFuture()) {
            if ($health !== []) {
                $deployment->update(['health' => $health]);
            }

            self::dispatch($deployment)->delay((int) config('builder.publishing.confirm.interval_seconds'));

            return;
        }

        $deployment->update([
            'status' => DeploymentStatus::NeedsAttention,
            'health' => $health ?: $deployment->health,
            'error' => $progress === ReleaseProgress::Pending
                ? __('Your hosting is taking longer than usual to start the new version.')
                : __('Your hosting has the new version, but the app is not answering properly at :address.', ['address' => $address]),
            'finished_at' => now(),
        ]);
    }

    /**
     * Record an unexpected failure, without claiming the app is online.
     */
    public function failed(?Throwable $exception): void
    {
        $this->deployment->update([
            'status' => DeploymentStatus::NeedsAttention,
            'error' => __('Your hosting has the new version, but I could not check that the app is online.'),
            'finished_at' => now(),
        ]);
    }

    /**
     * Request one path. Anything below 400 passes: a redirect to a sign-in
     * page is a working app.
     *
     * @return array{path: string, status: int|null, passed: bool}
     */
    protected function check(string $address, string $path): array
    {
        try {
            $status = Http::timeout((int) config('builder.publishing.confirm.timeout'))
                ->withoutRedirecting()
                ->get($address.'/'.ltrim($path, '/'))
                ->status();
        } catch (ConnectionException) {
            $status = null;
        }

        return ['path' => $path, 'status' => $status, 'passed' => $status !== null && $status < 400];
    }
}
