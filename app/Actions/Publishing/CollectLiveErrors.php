<?php

namespace App\Actions\Publishing;

use App\Models\Deployment;
use App\Publishing\PublishingHostManager;
use Carbon\CarbonImmutable;

/**
 * Ask the host which errors the online version raised since we last asked,
 * and add them to the version's tally. The same kind of error is counted,
 * not listed again, so an app failing on every request stays readable.
 */
class CollectLiveErrors
{
    public function __construct(private PublishingHostManager $hosts) {}

    /**
     * Collect the deployment's new errors, returning how many there were, or
     * null when its host cannot tell.
     */
    public function handle(Deployment $deployment): ?int
    {
        $to = CarbonImmutable::now();
        $from = $deployment->live_errors_checked_at ?? $deployment->finished_at ?? $deployment->created_at ?? $to;
        $errors = $this->hosts->driver($deployment->host ?? $deployment->project->publishingHost())->errors($deployment, $from, $to);

        if ($errors === null) {
            return null;
        }

        $kinds = collect($deployment->live_errors ?? [])->keyBy(fn (array $kind) => $this->fingerprint($kind['class'], $kind['message']));

        foreach ($errors as $error) {
            $key = $this->fingerprint($error['class'], $error['message']);
            $kind = $kinds->get($key, ['class' => $error['class'], 'message' => $error['message'], 'count' => 0, 'last_at' => $error['at']]);
            $kind['count']++;
            $kind['last_at'] = max($kind['last_at'], $error['at']);
            $kinds->put($key, $kind);
        }

        $deployment->update([
            'live_errors' => $kinds->sortByDesc('count')->take((int) config('builder.publishing.errors.kinds'))->values()->all(),
            'live_errors_checked_at' => $to,
        ]);

        return count($errors);
    }

    /**
     * Group errors by kind: numbers in a message (IDs, counts, times) differ
     * between two occurrences of the same fault.
     */
    protected function fingerprint(?string $class, string $message): string
    {
        return sha1(($class ?? '').'|'.preg_replace('/\d+/', '#', $message));
    }
}
