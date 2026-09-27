<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BrowserTestCase;

/*
| Browser tests drive the app in Chromium through Pest's browser plugin. It
| serves the app inside the test process, so the tests share the test
| database and config, and never touch the development server or `.env`.
| The other suites are PHPUnit classes and need nothing here.
*/

pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');
