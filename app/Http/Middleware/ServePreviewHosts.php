<?php

namespace App\Http\Middleware;

use App\Models\Preview;
use App\Previews\PreviewGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands requests for preview hosts ({host}.{preview domain}) to the preview
 * gateway before routing, sessions or cookie handling, so a preview host can
 * never reach the control plane's own routes.
 */
class ServePreviewHosts
{
    public function __construct(protected PreviewGateway $gateway) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $domain = strtolower((string) config('builder.preview.domain'));
        $host = strtolower($request->getHost());

        if ($domain === '' || ! str_ends_with($host, '.'.$domain)) {
            return $next($request);
        }

        $label = substr($host, 0, -strlen('.'.$domain));

        $preview = preg_match('/^[a-z0-9]+$/', $label) === 1
            ? Preview::query()->where('host', $label)->first()
            : null;

        return $this->gateway->handle($request, $preview);
    }
}
