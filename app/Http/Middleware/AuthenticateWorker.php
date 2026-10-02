<?php

namespace App\Http\Middleware;

use App\Enums\RunStatus;
use App\Models\Project;
use App\Models\Run;
use App\Runs\WorkerTask;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let in only a worker with a live token for a change that has not ended,
 * or for an app whose changes the owner's own tool writes, and bind that
 * change as the request's whole scope. Nothing else here accepts these
 * tokens, and the owner's session opens nothing here.
 */
class AuthenticateWorker
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());
        $owner = $token?->tokenable;

        abort_unless($token !== null && ($token->expires_at === null || $token->expires_at->isFuture()), 401);

        $task = match (true) {
            $owner instanceof Run && $token->can('task') && ! $owner->status->finished() => new WorkerTask($owner),
            $owner instanceof Project && $token->can('project') => new WorkerTask(self::waiting($owner), wholeApp: true, project: $owner),
            default => abort(401),
        };

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('worker_token', $token->getKey());
        app()->instance(WorkerTask::class, $task);

        return $next($request);
    }

    /**
     * Get the app's oldest change that waits for the owner's tool, one at a
     * time, as each builds on the last. A change waiting on the owner's
     * answer is left to them.
     */
    public static function waiting(Project $project): ?Run
    {
        return Run::query()
            ->where('driver', 'worker')
            // Only once our planner decided there is something to build: a
            // question about the app is answered from the plan, so the
            // owner's tool never spends its turns on it.
            ->whereIn('status', [RunStatus::Implementing, RunStatus::Verifying, RunStatus::Reviewing])
            ->whereHas('featureRequest', fn ($query) => $query->whereBelongsTo($project))
            ->oldest('id')
            ->first();
    }
}
