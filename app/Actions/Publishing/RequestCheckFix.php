<?php

namespace App\Actions\Publishing;

use App\Actions\Features\RequestFeature;
use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Turn the checks that kept the app from going online, the host's words
 * when it could not start the new version, or the checks that found it
 * not working once it was, into an ask to fix them, with one click. The
 * owner's words stay plain; the builder gets what each check said.
 */
class RequestCheckFix
{
    public function __construct(private RequestFeature $requestFeature) {}

    /**
     * Ask for a fix of the latest publish's failed checks, or get the fix
     * already asked for, so a second click does not pay for the same work
     * twice.
     *
     * Sending the same version again cannot mend an app that went online
     * but does not work, so what its address answered is the fix's
     * evidence too.
     *
     * @throws ValidationException when the latest publish did not fail a check.
     */
    public function handle(Project $project, User $requester): FeatureRequest
    {
        $latest = $project->deployments()->latest('id')->first();
        $failed = match ($latest instanceof Deployment ? $latest->status : null) {
            DeploymentStatus::Failed => [
                ...array_map(fn (array $check) => ['name' => $check['name'], 'output' => $check['output'] ?? ''], array_values(array_filter($latest->checks ?? [], fn (array $check) => ! $check['passed']))),
                // Every check passed, but the host could not build or start it.
                ...($latest->error_cause === 'release' ? [['name' => 'Starting it on the hosting', 'output' => (string) $latest->error_details]] : []),
            ],
            DeploymentStatus::NeedsAttention => $this->unhealthy($latest),
            default => [],
        };

        if ($failed === []) {
            throw ValidationException::withMessages(['fix' => __('No check stopped your app going online.')]);
        }

        $asked = $project->featureRequests()
            ->where('failed_checks->deployment_id', $latest->id)
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled])
            ->latest('id')
            ->first();

        if ($asked !== null) {
            return $asked;
        }

        $online = $latest->status === DeploymentStatus::NeedsAttention;

        // The fix is for what goes online: the main app, not an open idea.
        return $this->requestFeature->handle(
            $project,
            $requester,
            $online ? __('Fix what stops my app working online.') : __('Fix what stopped my app going online.'),
            experiment: null,
            failedChecks: ['deployment_id' => $latest->id, 'checks' => $failed, ...($online ? ['online' => true] : [])],
        );
    }

    /**
     * Describe the checks of the app's address that failed after its new
     * version went online. A host that never finished starting it has none:
     * waiting, not a change to the code, is the step then.
     *
     * @return list<array{name: string, output: string}>
     */
    protected function unhealthy(Deployment $deployment): array
    {
        $seconds = (int) config('builder.publishing.confirm.timeout');

        return array_values(array_map(fn (array $check) => ($check['key'] ?? null) === 'auth.sign-in'
            ? ['name' => "Signing in at /{$this->path($check)}", 'output' => $check['status'] === null
                ? "A sign-in attempt did not get an answer within {$seconds} seconds."
                : "A sign-in attempt with an account that does not exist got status {$check['status']}, not a refusal."]
            : ['name' => "Opening /{$this->path($check)}", 'output' => $check['status'] === null
                ? "It did not answer within {$seconds} seconds."
                : "It answered with status {$check['status']}."],
            array_filter($deployment->health ?? [], fn (array $check) => ! $check['passed'])));
    }

    /**
     * @param  array{path: string}  $check
     */
    protected function path(array $check): string
    {
        return ltrim($check['path'], '/');
    }
}
