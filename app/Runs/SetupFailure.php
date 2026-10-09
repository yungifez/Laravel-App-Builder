<?php

namespace App\Runs;

use App\Previews\PreviewFailure;

/**
 * Says in the owner's words why their app could not be got ready for a
 * change: the stage it reached, whose fault it is and what to do next. It
 * is the first line of the run's error; what the step printed follows it
 * for operators. The stages are the same as a preview's.
 */
class SetupFailure
{
    /**
     * Why a setup step stopped the change before it began.
     */
    public static function step(string $name, bool $timedOut): string
    {
        ['stage' => $stage, 'app' => $app] = PreviewFailure::stage($name);

        if ($timedOut) {
            return __('This is our fault: :stage took too long, so I stopped before changing anything. Nothing in your app changed. Try again.', ['stage' => $stage]);
        }

        // Every change starts from the app as it is, so asking for another
        // one would stop here too; a developer can look at the app itself.
        return $app
            ? __('Something in your app\'s code went wrong while :stage, before I changed anything. Nothing in your app changed. Ask one of our developers to look at it.', ['stage' => $stage])
            : self::ours($stage);
    }

    /**
     * Something of ours failed while the app was got ready.
     */
    public static function ours(string $stage = 'getting your app ready to change'): string
    {
        return __('This is our fault: something went wrong on our side while :stage. Nothing in your app changed. Try again.', ['stage' => $stage]);
    }

    /**
     * A change this one follows on from no longer applies to the app.
     */
    public static function changeNoLongerFits(): string
    {
        return __('This is our fault: your app changed after the change this one follows on from, so it no longer fits. Nothing in your app changed. Ask for the change again.');
    }
}
