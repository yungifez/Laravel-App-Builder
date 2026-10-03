<?php

namespace App\Actions\Developers;

use App\Models\DeveloperApplication;
use App\Models\User;
use App\Notifications\DeveloperApplied;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Ask to answer owners' questions as one of our developers. Asking again
 * while waiting changes what was written; asking after a no starts a new
 * wait. The operators hear each time, since a person decides.
 */
class ApplyAsDeveloper
{
    /**
     * Keep the application and tell the operators.
     *
     * @throws ValidationException when the person is approved already.
     */
    public function handle(User $user, string $about, ?string $link): DeveloperApplication
    {
        $application = $user->developerApplication;

        if ($application?->approved() === true) {
            throw ValidationException::withMessages(['about' => __('You can answer questions already.')]);
        }

        $application = $user->developerApplication()->updateOrCreate([], [
            'about' => trim($about),
            'link' => $link,
            'approved_at' => null,
            'declined_at' => null,
            'decided_by' => null,
        ]);
        $user->setRelation('developerApplication', $application);

        /** @var list<string> $operators */
        $operators = config('operations.operators');

        if ($operators !== []) {
            Notification::route('mail', $operators)->notify(new DeveloperApplied($application));
        }

        return $application;
    }
}
