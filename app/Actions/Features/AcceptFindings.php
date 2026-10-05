<?php

namespace App\Actions\Features;

use App\Features\AppDrift;
use App\Features\AppFaults;
use App\Features\AppRoutes;
use App\Features\BoundaryCode;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Record that the owner wants what a boundary rule found in a change, for
 * example a log of each time someone is turned away, written while the app
 * checks who may act. The same goes for what a caused failure left behind,
 * for example a message that is kept when the email about it cannot be
 * sent. The owner read what it costs in the proof first.
 * This covers each finding of that rule in the change by what it is, never
 * the rule itself, and only this change: once the change is kept, a later
 * change that does more of the same is asked about again.
 */
class AcceptFindings
{
    /**
     * Accept every finding of one rule in the change's latest checks.
     *
     * @throws ValidationException when the change is kept or the rule found nothing.
     */
    public function handle(FeatureRequest $featureRequest, string $kind, User $user): void
    {
        if ($featureRequest->isAccepted()) {
            throw ValidationException::withMessages([
                'kind' => __('This is already part of your app.'),
            ]);
        }

        $evidence = $featureRequest->verifications()->latest('id')->first()?->evidence;
        $boundaries = $evidence['boundaries'] ?? null;
        $found = [
            ...array_map(BoundaryCode::identity(...), array_filter([...$boundaries['findings'] ?? [], ...$boundaries['read'] ?? []], fn (array $finding) => $finding['kind'] === $kind)),
            ...array_map(AppFaults::identity(...), AppFaults::findings($evidence['faults'] ?? null, $kind)),
            ...array_map(AppDrift::identity(...), $kind === AppDrift::GREW ? $evidence['drift']['findings'] ?? [] : []),
            ...array_map(AppRoutes::identity(...), array_filter(AppRoutes::findings($evidence['routes'] ?? null), fn (array $finding) => $finding['kind'] === $kind)),
        ];

        if ($found === []) {
            throw ValidationException::withMessages([
                'kind' => __('The latest checks no longer find this.'),
            ]);
        }

        foreach (array_unique($found) as $identity) {
            $featureRequest->acceptedFindings()->firstOrCreate(['identity' => $identity], ['kind' => $kind, 'user_id' => $user->id]);
        }
    }

    /**
     * Take the owner's word back, so the rule's findings count again.
     */
    public function restore(FeatureRequest $featureRequest, string $kind): void
    {
        $featureRequest->acceptedFindings()->where('kind', $kind)->delete();
    }

    /**
     * Get what the owner accepted in the change, by what each finding is.
     *
     * @return list<string>
     */
    public function identities(?FeatureRequest $featureRequest): array
    {
        return array_values($featureRequest?->acceptedFindings()->pluck('identity')->map(fn (mixed $identity) => (string) $identity)->all() ?? []);
    }
}
