<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lab404\Impersonate\Services\ImpersonateManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * An operator signed in as a person may look and help, but never changes
 * how the person signs in or pays, or deletes their account.
 */
class BlockWhileSignedInAsSomeone
{
    public function __construct(private ImpersonateManager $impersonate) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonate->isImpersonating()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('You are signed in as someone else. Go back to your own account to do this.')]);

            return back();
        }

        return $next($request);
    }
}
