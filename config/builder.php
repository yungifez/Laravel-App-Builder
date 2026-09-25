<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Generator
    |--------------------------------------------------------------------------
    |
    | The generator turns an owner's feature request into a change (a patch)
    | for the project, plus the steps in that change the owner can select and
    | ask to change. The "reference" generator replays known-good solutions
    | listed in a manifest; it stands in for the AI agent until that exists.
    |
    */

    'generator' => env('BUILDER_GENERATOR', 'reference'),

    /*
    |--------------------------------------------------------------------------
    | Model Tiers
    |--------------------------------------------------------------------------
    |
    | Features are built by three model roles: a frontier "planner", an
    | economical "coder" and an independent "reviewer". Each role names a
    | laravel/ai provider (config/ai.php) and model. Leave a provider empty to
    | use the AI SDK default, and a model empty to use that provider's default.
    | Point every role at the same model to compare against a single stronger
    | model; the split is a strategy, not a dependency.
    |
    */

    'models' => [
        'planner' => [
            'provider' => env('BUILDER_PLANNER_PROVIDER'),
            'model' => env('BUILDER_PLANNER_MODEL'),
        ],
        'coder' => [
            'provider' => env('BUILDER_CODER_PROVIDER'),
            'model' => env('BUILDER_CODER_MODEL'),
        ],
        'reviewer' => [
            'provider' => env('BUILDER_REVIEWER_PROVIDER'),
            'model' => env('BUILDER_REVIEWER_MODEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | How a generated change is verified: the project is copied into a fresh
    | workspace, the change (and every change it follows up on) is applied,
    | then the "setup" commands and the "checks" run in order. A failing setup
    | command stops the run; every check runs and is reported. The defaults
    | suit a Laravel application with Composer and npm lockfiles.
    |
    */

    'verification' => [
        'workspace_driver' => env('BUILDER_VERIFICATION_DRIVER', 'local'),

        // Platform-owned acceptance suites and their runner configuration.
        // They are copied fresh into tests/Acceptance after the checks, replacing
        // anything the change put there, and run with this directory's
        // phpunit.xml. Relative paths resolve from the application's base path.
        'acceptance' => [
            'path' => env('BUILDER_ACCEPTANCE_PATH'),
            'timeout' => 600,
        ],

        'setup' => [
            ['name' => 'Create .env', 'command' => ['cp', '.env.example', '.env'], 'timeout' => 30],
            ['name' => 'Install PHP dependencies', 'command' => ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'], 'timeout' => 900],
            ['name' => 'Generate app key', 'command' => ['php', 'artisan', 'key:generate', '--no-interaction'], 'timeout' => 60],
            ['name' => 'Install Node dependencies', 'command' => ['npm', 'ci', '--no-audit', '--no-fund'], 'timeout' => 600],
            ['name' => 'Generate route helpers', 'command' => ['php', 'artisan', 'wayfinder:generate', '--with-form'], 'timeout' => 120],
        ],

        'checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 600],
            ['name' => 'Static analysis', 'command' => ['vendor/bin/phpstan', 'analyse', '--no-progress'], 'timeout' => 600],
            ['name' => 'PHP formatting', 'command' => ['vendor/bin/pint', '--test'], 'timeout' => 300],
            ['name' => 'Frontend format and lint', 'command' => ['npx', 'vp', 'check'], 'timeout' => 300],
            ['name' => 'TypeScript', 'command' => ['npm', 'run', 'types:check'], 'timeout' => 300],
        ],
    ],

    'generators' => [

        'reference' => [
            // Directory holding manifest.json and the patches it names.
            // Relative paths are resolved from the application's base path.
            'path' => env('BUILDER_REFERENCE_SOLUTIONS'),
        ],

    ],

];
