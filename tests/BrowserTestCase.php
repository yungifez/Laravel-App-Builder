<?php

namespace Tests;

use Illuminate\Support\Facades\Vite;

abstract class BrowserTestCase extends TestCase
{
    /**
     * Serve the built frontend, because a real browser needs the scripts
     * that the other tests leave out.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withVite();
        Vite::clearResolvedInstance();
    }
}
