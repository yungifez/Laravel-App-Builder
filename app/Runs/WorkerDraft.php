<?php

namespace App\Runs;

use App\Models\Run;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The change a worker makes on our side, file by file, when it has no
 * folder of its own, as in a chat in the Claude app. It lives in the
 * change's workspace; this keeps it as a patch too, so a try with a patch
 * or an applied hand-in, which put the workspace back, never loses it.
 */
class WorkerDraft
{
    /**
     * Get the change made so far: the one kept here, else the one last
     * handed back, which a fix pass goes on from.
     */
    public function patch(Run $run): string
    {
        $kept = Cache::get(self::key($run));

        if (is_string($kept)) {
            return $kept;
        }

        $submitted = $run->events()->where('type', 'worker_submitted')->reorder('sequence', 'desc')->first();

        return (string) ($submitted->data['patch'] ?? '');
    }

    /**
     * Keep the change as it now is.
     *
     * @throws ValidationException when it is too large to hand back.
     */
    public function keep(Run $run, string $patch): void
    {
        if (strlen($patch) > (int) config('builder.agents.workers.max_patch_kb') * 1024) {
            throw ValidationException::withMessages(['patch' => __('The change is too large to hand back in one patch. Make it smaller.')]);
        }

        Cache::put(self::key($run), $patch, now()->addMinutes((int) config('builder.agents.workers.minutes')));
    }

    protected static function key(Run $run): string
    {
        return "runs:{$run->id}:worker-draft";
    }
}
