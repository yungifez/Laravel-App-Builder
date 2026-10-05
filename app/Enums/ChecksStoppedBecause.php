<?php

namespace App\Enums;

/**
 * Why the checks stopped before any check ran. Null on a verification
 * means they did not stop early.
 */
enum ChecksStoppedBecause: string
{
    /** The change could not be put onto a copy of the app. */
    case DoesNotApply = 'does_not_apply';

    /** The change edits the files the checks rely on. */
    case ProtectedInputs = 'protected_inputs';

    /** The app could not be got ready, after the change edited what it installs. */
    case ChangeInstall = 'change_install';

    /** The app could not be got ready, and the change did not touch what it installs. */
    case Setup = 'setup';

    /**
     * Determine if making the change again is the way out: the change
     * itself is what stopped the checks, so checking it again cannot help.
     */
    public function retryable(): bool
    {
        return $this !== self::Setup;
    }

    /**
     * Say why the checks stopped and what to do next, in the owner's
     * words.
     */
    public function message(): string
    {
        return match ($this) {
            self::DoesNotApply => __('I could not put this change onto a copy of your app, so the checks did not run. This is our fault. Try again to make it afresh.'),
            self::ProtectedInputs => __('This change edits the files that check your app, so I could not trust the checks. This is our fault. Try again to make it another way.'),
            self::ChangeInstall => __('This change adds something your app could not install, so the checks did not run. This is our fault. Try again to make it another way.'),
            self::Setup => __('I could not get a copy of your app ready, so the checks did not run. The change did not touch what your app installs. This is our fault. Check again in a few minutes.'),
        };
    }
}
