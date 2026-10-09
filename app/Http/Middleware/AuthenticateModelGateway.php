<?php

namespace App\Http\Middleware;

use App\Runs\ModelGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let in only a run's gateway token, for the provider it was opened for.
 * Anthropic's SDK sends the token as "x-api-key", OpenAI's as a bearer
 * token; they hold the gateway token in place of the real key.
 */
class AuthenticateModelGateway
{
    public function __construct(protected ModelGateway $gateway) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) ($request->header('x-api-key') ?: $request->bearerToken());
        $grant = $token === '' ? null : $this->gateway->grant($token);

        abort_if($grant === null || $grant['provider'] !== $request->route('provider'), 401);
        abort_unless($this->gateway->allowsRequest((string) $request->route('provider'), $request->method(), (string) $request->route('path')), 403);

        $request->attributes->set('gateway_token', $token);

        return $next($request);
    }
}
