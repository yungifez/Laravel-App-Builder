<?php

namespace App\Actions\Decisions;

use App\Models\Decision;
use App\Models\Run;
use Illuminate\Support\Facades\Config;

class ActOnDecision
{
    /**
     * Determine if a decision about the run's request may act on the given
     * answer (architecture §26.9). It may only when the operator switched
     * that decision on, the answer is in, and the model was at least as
     * sure as the decision's threshold asks. Everything else leaves the
     * run on its usual path, as in shadow mode: a decision that has not
     * come back yet is never waited for.
     *
     * A decision that acts is marked, and the run says so, so its runs can
     * be compared with the runs it left alone (`builder:decisions`).
     */
    public function handle(Run $run, string $name, string $choice): bool
    {
        if (! in_array($name, Config::array('builder.decisions.act'), true)) {
            return false;
        }

        $decision = $run->featureRequest->decisions()->where('name', $name)->first();

        if (! $decision instanceof Decision || $decision->choice !== $choice || ! $decision->confident()) {
            return false;
        }

        $decision->update(['acted' => true]);
        $run->recordEvent('decision_acted', ['name' => $name, 'choice' => $choice, 'confidence' => $decision->confidence]);

        return true;
    }
}
