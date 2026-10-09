<?php

namespace App\Jobs;

use App\Actions\Features\RequestFeature;
use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TidyShortcuts implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  FeatureRequest  $featureRequest  The kept change that took the shortcuts
     * @param  list<array{rule: string, path: string, line: int}>  $shortcuts  The shortcuts to fix
     * @param  string  $tier  "light" for the coding agents' light model, "full" for their usual one
     */
    public function __construct(
        public FeatureRequest $featureRequest,
        public array $shortcuts,
        public string $tier = 'light',
    ) {}

    /**
     * An owner who keeps working holds the tidy-up back for a day at most.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addDay();
    }

    /**
     * Ask for a change that fixes the shortcuts, in the app's own name,
     * once the owner has no change being built or waiting for them: a
     * commit made meanwhile would send their change to be built again.
     */
    public function handle(RequestFeature $requestFeature): void
    {
        $request = $this->featureRequest->fresh();

        if (! config('builder.verification.shortcuts.tidy.enabled') || $request === null || $request->reverted_at !== null || $request->project->owner === null) {
            return;
        }

        if ($this->ownerIsWorking($request)) {
            $this->release((int) config('builder.verification.shortcuts.tidy.wait_minutes') * 60);

            return;
        }

        $requestFeature->handle(
            $request->project,
            $request->project->owner,
            trans_choice('Tidy up one thing in my app\'s code.|Tidy up :count things in my app\'s code.', count($this->shortcuts)),
            experiment: null,
            tidy: ['of' => $request->id, 'tier' => $this->tier, 'shortcuts' => $this->shortcuts],
        );
    }

    /**
     * Determine if a change of the main app is being built, or is built
     * and was looked at lately without being kept or put aside.
     */
    protected function ownerIsWorking(FeatureRequest $request): bool
    {
        return $request->project->featureRequests()
            ->whereNull('experiment_id')
            ->whereNull('commit_sha')
            ->whereNull('dismissed_at')
            ->where(fn ($query) => $query
                ->where('status', FeatureRequestStatus::Generating)
                ->orWhere(fn ($query) => $query
                    ->where('status', FeatureRequestStatus::Generated)
                    ->where('updated_at', '>', now()->subMinutes((int) config('builder.verification.shortcuts.tidy.idle_minutes')))))
            ->exists();
    }
}
