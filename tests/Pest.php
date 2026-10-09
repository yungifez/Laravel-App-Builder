<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BrowserTestCase;

/*
| Browser tests drive the app in Chromium through Pest's browser plugin. It
| serves the app inside the test process, so the tests share the test
| database and config, and never touch the development server or `.env`.
| The other suites are PHPUnit classes and need nothing here.
*/

// The plugin's Playwright server outlives the run unless it watches the
// run itself; see the script. Symfony's Process passes on only what is
// also in $_SERVER or $_ENV.
$nodeOptions = trim(getenv('NODE_OPTIONS').' --require '.__DIR__.'/Browser/exit-with-pest.cjs');
putenv("NODE_OPTIONS={$nodeOptions}");
$_SERVER['NODE_OPTIONS'] = $_ENV['NODE_OPTIONS'] = $nodeOptions;

pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');
