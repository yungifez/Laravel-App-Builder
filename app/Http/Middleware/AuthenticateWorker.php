<?php

namespace App\Http\Middleware;

use App\Models\Run;
use App\Runs\WorkerTask;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let in only a worker with a live token for a change that has not ended,
 * and bind that change as the request's whole scope. Nothing else here
 * accepts these tokens, and the owner's session opens nothing here.
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
        $run = $token?->tokenable;

        abort_unless(
            $token !== null
                && $token->can('task')
                && ($token->expires_at === null || $token->expires_at->isFuture())
                && $run instanceof Run
                && ! $run->status->finished(),
            401,
        );

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('worker_token', $token->getKey());
        app()->instance(WorkerTask::class, new WorkerTask($run));

        return $next($request);
    }
}
