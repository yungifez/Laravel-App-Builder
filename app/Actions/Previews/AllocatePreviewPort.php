<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Preview;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class AllocatePreviewPort
{
    /**
     * Reserve a port for the preview: one in the configured range that no
     * running preview holds and nothing on this host is listening on.
     *
     * Picking and recording happen under one lock, so previews starting at
     * the same time on different workers never get the same port.
     *
     * @throws RuntimeException when every port is taken.
     */
    public function handle(Preview $preview): int
    {
        return Cache::lock('previews:ports', 30)->block(15, function () use ($preview) {
            $port = $this->pick();

            $preview->update(['port' => $port]);

            return $port;
        });
    }

    /**
     * Pick a free port in the configured range.
     *
     * @throws RuntimeException when every port is taken.
     */
    protected function pick(): int
    {
        /** @var array{0: int, 1: int} $range */
        $range = config('builder.preview.ports');

        $taken = Preview::query()
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->whereNotNull('port')
            ->pluck('port')
            ->all();

        foreach (range($range[0], $range[1]) as $port) {
            if (! in_array($port, $taken, true) && $this->isFree($port)) {
                return $port;
            }
        }

        throw new RuntimeException('No preview port is free.');
    }

    /**
     * Determine if nothing is listening on the port.
     */
    protected function isFree(int $port): bool
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
