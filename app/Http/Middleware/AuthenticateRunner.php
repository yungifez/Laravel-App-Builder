<?php

namespace App\Http\Middleware;

use App\Workspaces\Boxes\BoxProviderManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let in only a box runner with a token from the box provider, and remember
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
        $runner = $token === '' ? null : $this->providers->driver()->authenticate($token);

        abort_if($runner === null, 401);

        $request->attributes->set('runner', $runner);

        return $next($request);
    }
}
