<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Lab404\Impersonate\Services\ImpersonateManager;

/**
 * An operator signs in as a person to see what they see, then goes back
 * to their own account. Each start and end is logged with both names.
 */
class ImpersonationController extends Controller
{
    /**
     * Sign in as the person.
     */
    public function store(Request $request, User $user, ImpersonateManager $impersonate): RedirectResponse
    {
        $operator = $request->user();
        abort_unless($operator->canImpersonate() && $user->canBeImpersonated() && ! $impersonate->isImpersonating(), 403);

        $impersonate->take($operator, $user);
        Log::notice('An operator signed in as a person.', ['operator' => $operator->id, 'person' => $user->id]);

        return to_route('projects.index');
    }

    /**
     * Go back to the operator's own account.
     */
    public function destroy(Request $request, ImpersonateManager $impersonate): RedirectResponse
    {
        abort_unless($impersonate->isImpersonating(), 404);

        $person = $request->user();
        $operator = $impersonate->getImpersonatorId();
        $impersonate->leave();
        Log::notice('An operator went back to their own account.', ['operator' => $operator, 'person' => $person->id]);

        return to_route('operations.people.show', $person);
    }
}
