<?php

namespace App\Http\Middleware;

use App\Enums\RunStatus;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Runs\WorkerTask;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let in only a worker with a live token for a change that has not ended,
 * or for an app whose changes the owner's own tool writes, or the owner's
 * tool signed in to one of their apps, and bind that change as the
 * request's whole scope. Nothing else here accepts these tokens, and the
 * owner's session opens nothing here.
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
        $project = $request->route('project');

        app()->instance(WorkerTask::class, is_string($project) ? $this->signedIn($request, $project) : $this->byToken($request));

        return $next($request);
    }

    /**
     * Let in a tool by the token we made for it: one change's, or one
     * app's.
     */
    protected function byToken(Request $request): WorkerTask
    {
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());
        $owner = $token?->tokenable;

        abort_unless($token !== null && ($token->expires_at === null || $token->expires_at->isFuture()), 401);

        $task = match (true) {
            // A change that ended still answers how it ended, while its
            // token lasts (TransitionRun shortens it).
            $owner instanceof Run && $token->can('task') => new WorkerTask($owner),
            $owner instanceof Project && $token->can('project') => new WorkerTask(self::waiting($owner), wholeApp: true, project: $owner),
            default => abort(401),
        };

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('worker_token', $token->getKey());

        return $task;
    }

    /**
     * Let in a tool the owner signed in through OAuth, as a connector in
     * the Claude app, VS Code or Cursor, at the address of one of their
     * apps. Such a tool has no folder of its own to start from, so the
     * address names the app; the sign-in names only the person.
     */
    protected function signedIn(Request $request, string $project): WorkerTask
    {
        $user = Auth::guard('api')->user();

        // A 401 sends the tool to sign in, which a 403 would not help.
        abort_unless($user instanceof User && $user->tokenCan(Registrar::OAUTH_SCOPE) && ! $user->suspended(), 401);

        $project = Str::isUuid($project) ? Project::query()->where('uuid', $project)->first() : null;

        abort_unless($project !== null && $user->can('requestFeatures', $project), 403);

        // While the tool keeps working, the app stays connected to it, as
        // its sign-in renews itself.
        $project->tokens()
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()->addDays((int) config('builder.agents.workers.project_days'))]);

        $request->attributes->set('worker_token', 'user:'.$user->getKey());

        return new WorkerTask(self::waiting($project), wholeApp: true, project: $project);
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
