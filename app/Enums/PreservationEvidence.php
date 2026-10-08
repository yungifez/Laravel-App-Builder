<?php

namespace App\Enums;

/**
 * What the platform can honestly say about something a change was meant to
 * keep as it is. None of these claims the behaviour was proven to hold: no
 * test is linked to a single behaviour yet, so the strongest positive claim
 * is that the area's related tests passed.
 */
enum PreservationEvidence: string
{
    /**
     * A blocking review finding names a file in the area, or the change
     * reached the area where the brief did not expect it.
     */
    case RegressionSuspected = 'regression_suspected';

    /**
     * A failing test belongs to the area.
     */
    case TestsFailed = 'tests_failed';

    /**
     * The area has tests and the whole suite passed. That does not show the
     * specific behaviour was exercised.
     */
    case RelatedTestsPassed = 'related_tests_passed';

    /**
     * No file the area claims was edited. Other code can still change how it
     * behaves.
     */
    case NotEdited = 'not_edited';

    case NotChecked = 'not_checked';
}
