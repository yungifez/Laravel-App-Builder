<?php

namespace App\Enums;

/**
 * What the owner can do about a stopped change, each one a thing the
 * change's page offers: try again, open Settings, answer, or ask one of
 * our developers.
 */
enum NextStep: string
{
    case Retry = 'retry';
    case Settings = 'settings';
    case Answer = 'answer';
    case Contact = 'contact';

    /**
     * Get the words that tell the owner to take this step.
     */
    public function words(): string
    {
        return match ($this) {
            self::Retry => __('Try again'),
            self::Settings => __('Settings'),
            self::Answer => __('answer'),
            self::Contact => __('one of our developers'),
        };
    }
}
