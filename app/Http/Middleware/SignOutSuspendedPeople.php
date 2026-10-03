<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lab404\Impersonate\Services\ImpersonateManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person an operator suspended is signed out at their next request, and
 * at once again if they log in. An operator signed in as them may still
 * look around.
 */
class SignOutSuspendedPeople
{
    public function __construct(private ImpersonateManager $impersonate) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->suspended() || $this->impersonate->isImpersonating()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('status', __('We stopped this account. To ask why, use the contact page.'));
    }
}
