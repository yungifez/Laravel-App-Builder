<?php

namespace App\Http\Middleware;

use App\Models\Runner;
use App\Workspaces\Boxes\BoxProviderManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let in only a box runner with a token from a box provider, and remember
 * which runner it is. A runner's token opens its own commands and nothing
 * else in the control plane.
 */
class AuthenticateRunner
{
    public function __construct(protected BoxProviderManager $providers) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $runner = $token === '' ? null : $this->providers->authenticate($token);

        abort_if($runner === null, 401);

        $request->attributes->set('runner', $runner);

        // A pool runner counts as online while it keeps asking; a write at
        // most every few seconds is enough for that.
        Runner::query()
            ->where('name', $runner)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(15)))
            ->update(['last_seen_at' => now()]);

        return $next($request);
    }
}
