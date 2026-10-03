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
use Illuminate\Support\Str;
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

        // Pages answering is not the same as people getting in.
        if ($health !== [] && ! in_array(false, array_column($health, 'passed'), true) && ($signIn = $this->checkSignIn($address)) !== null) {
            $health[] = $signIn;
        }

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
            'error' => match (true) {
                $progress === ReleaseProgress::Pending => __('Your hosting is taking longer than usual to start the new version.'),
                collect($health)->contains(fn (array $check) => ($check['key'] ?? null) === 'auth.sign-in' && ! $check['passed']) => __('Your app is online at :address, but people cannot sign in.', ['address' => $address]),
                default => __('Your hosting has the new version, but the app is not answering properly at :address.', ['address' => $address]),
            },
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
     * Try to sign in with an account that cannot exist. A working app turns
     * it down the usual way (wrong details, or too many tries); a broken
     * session, database or sign-in page answers with an error instead. An
     * app without a sign-in page has nothing to check.
     *
     * @return array{path: string, status: int|null, passed: bool, key: string}|null
     */
    protected function checkSignIn(string $address): ?array
    {
        $path = (string) config('builder.publishing.confirm.sign_in_path');

        if ($path === '') {
            return null;
        }

        $url = $address.'/'.ltrim($path, '/');
        $request = fn () => Http::timeout((int) config('builder.publishing.confirm.timeout'))->withoutRedirecting();

        try {
            $page = $request()->get($url);

            if ($page->status() === 404) {
                return null;
            }

            $status = $page->status();

            if ($status < 400) {
                $token = $page->cookies()->getCookieByName('XSRF-TOKEN')?->getValue();

                $status = $request()
                    ->withOptions(['cookies' => $page->cookies()])
                    ->withHeaders(array_filter(['X-XSRF-TOKEN' => $token === null ? null : urldecode($token)]))
                    ->acceptJson()
                    ->post($url, ['email' => 'publish-check@example.invalid', 'password' => Str::random(32)])
                    ->status();
            }
        } catch (ConnectionException) {
            $status = null;
        }

        return ['path' => $path, 'status' => $status, 'passed' => $status !== null && ($status < 400 || in_array($status, [401, 422, 429], true)), 'key' => 'auth.sign-in'];
    }

    /**
     * Request one path. Anything below 400 passes: a redirect to a sign-in
     * page is a working app. So does "not found" on any path but the home
     * page: an app that removed Laravel's health route answers that way,
     * and the home page is still checked.
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

        return ['path' => $path, 'status' => $status, 'passed' => $status !== null && ($status < 400 || ($status === 404 && trim($path, '/') !== ''))];
    }
}
