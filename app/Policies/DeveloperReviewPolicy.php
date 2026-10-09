<?php

namespace App\Policies;

use App\Models\DeveloperReview;
use App\Models\User;

/**
 * What a developer may do with an owner's question. Operators may do
 * anything. An approved developer sees a question while anyone may still
 * take it, and after that only when they took it, so an owner's code
 * reaches one developer, never all of them. Nobody answers about their
 * own app.
 */
class DeveloperReviewPolicy
{
    /**
     * Determine whether the user can read the question and the code.
     */
    public function view(User $user, DeveloperReview $developerReview): bool
    {
        if ($user->can('viewOperations')) {
            return true;
        }

        return $this->developer($user, $developerReview)
            && ($this->open($developerReview) || $this->mine($user, $developerReview));
    }

    /**
     * Determine whether the user can take the question.
     */
    public function claim(User $user, DeveloperReview $developerReview): bool
    {
        return ($user->can('viewOperations') || $this->developer($user, $developerReview))
            && $this->open($developerReview);
    }

    /**
     * Determine whether the user can give the question back.
     */
    public function release(User $user, DeveloperReview $developerReview): bool
    {
        return $developerReview->claimed_by !== null
            && $developerReview->waiting()
            && ($developerReview->claimed_by === $user->id || $user->can('viewOperations'));
    }

    /**
     * Determine whether the user can write or change the answer. A
     * developer takes the question first.
     */
    public function answer(User $user, DeveloperReview $developerReview): bool
    {
        if ($user->can('viewOperations')) {
            return true;
        }

        return $this->developer($user, $developerReview) && $this->mine($user, $developerReview);
    }

    /**
     * Determine if the user is an approved developer and the question is
     * not about their own app.
     */
    protected function developer(User $user, DeveloperReview $developerReview): bool
    {
        return $user->can('answerDeveloperQuestions')
            && $developerReview->project->user_id !== $user->id;
    }

    /**
     * Determine if anyone may still take the question.
     */
    protected function open(DeveloperReview $developerReview): bool
    {
        return $developerReview->claimed_by === null && $developerReview->waiting();
    }

    /**
     * Determine if the user took or answered the question.
     */
    protected function mine(User $user, DeveloperReview $developerReview): bool
    {
        return $developerReview->claimed_by === $user->id || $developerReview->answered_by === $user->id;
    }
}
