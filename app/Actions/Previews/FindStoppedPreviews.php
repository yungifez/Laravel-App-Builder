<?php

namespace App\Actions\Previews;

use App\Models\Preview;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class FindStoppedPreviews
{
    /**
     * Get the previews whose app no longer answers, though they count as
     * running: as when the machine that holds them restarts. Any answer
     * counts, even an error page; only a closed or silent port means the
     * app stopped. All are asked at once, so one silent app holds up the
     * rest by a few seconds at most.
     *
     * @param  Collection<int, Preview>  $previews
     * @return Collection<int, Preview>
     */
    public function handle(Collection $previews): Collection
    {
        $previews = $previews->filter(fn (Preview $preview) => filled($preview->upstream_url))->keyBy('id');

        if ($previews->isEmpty()) {
            return collect();
        }

        $answers = Http::pool(fn (Pool $pool) => $previews->map(fn (Preview $preview) => $pool->as((string) $preview->id)
            ->timeout(5)
            ->withOptions(['allow_redirects' => false])
            ->get((string) $preview->upstream_url))->all());

        return $previews->filter(fn (Preview $preview) => ($answers[(string) $preview->id] ?? null) instanceof ConnectionException)->values();
    }

    /**
     * Tell whether one preview's app still answers.
     */
    public function answers(Preview $preview): bool
    {
        return $this->handle(collect([$preview]))->isEmpty();
    }
}
