<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuspendPersonRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class PersonSuspensionController extends Controller
{
    /**
     * Stop the person signing in, or let them back.
     */
    public function update(SuspendPersonRequest $request, User $user): RedirectResponse
    {
        $suspended = $request->boolean('suspended');

        $user->forceFill(['suspended_at' => $suspended ? now() : null])->save();

        // Who stopped whom is kept for later questions.
        Log::notice($suspended ? 'Operator suspended a person.' : 'Operator let a person back.', [
            'operator_id' => $request->user()?->id,
            'user_id' => $user->id,
        ]);

        return to_route('operations.people.show', $user);
    }
}
