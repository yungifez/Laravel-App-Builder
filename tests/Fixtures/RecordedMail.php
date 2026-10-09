<?php

namespace Tests\Fixtures;

use Illuminate\Mail\Mailable;

/**
 * An email of the app RecordedApp stands in for.
 */
class RecordedMail extends Mailable
{
    public function build(): static
    {
        return $this->subject('Recorded')->html('Recorded');
    }
}
