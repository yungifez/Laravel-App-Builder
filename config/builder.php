<?php

use App\Runs\Tools\ApplyPatch;
use App\Runs\Tools\ListFiles;
use App\Runs\Tools\ReadFile;
use App\Runs\Tools\RunCommand;
use App\Runs\Tools\SearchFiles;
use App\Runs\Tools\WriteFile;

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
    | Construction Runs
    |--------------------------------------------------------------------------
    |
    | A run builds a feature request's change in its own workspace, through
    | server-side tools only, then hands the change to verification. The
    | "scripted" driver applies the generator's change through the tools. The
    | "agent" driver plans, builds and reviews with the three model roles
    | below, through the same tools and records.
    |
    | One worker writes to a run at a time. Its lease lasts "lease_seconds"
    | and is renewed by every tool call; a lease that expires can be taken
    | over by another worker, and the old worker's writes are then refused.
    |
    */

    'construction' => [
        'driver' => env('BUILDER_CONSTRUCTION_DRIVER', 'scripted'),

        'workspace_driver' => env('BUILDER_CONSTRUCTION_WORKSPACE_DRIVER', 'local'),

        'lease_seconds' => (int) env('BUILDER_RUN_LEASE_SECONDS', 300),

        // When a run is out of budget it stops and asks the owner how to
        // continue; transport retries do not count against it.
        'budgets' => [
            'operations' => (int) env('BUILDER_RUN_MAX_OPERATIONS', 30),
            'minutes' => (int) env('BUILDER_RUN_MAX_MINUTES', 20),
            // Attempts to fix a change that failed verification or review.
            'repairs' => (int) env('BUILDER_RUN_MAX_REPAIRS', 2),
        ],

        // What the planner sees besides the request: the file list (up to
        // "max_files") and these files when the project has them.
        'planning' => [
            'max_files' => 800,
            'context_files' => ['AGENTS.md', 'CLAUDE.md', 'composer.json', 'routes/web.php'],
        ],

        // Bounds on what tools accept and return.
        'limits' => [
            'read_bytes' => 262144,
            'write_bytes' => 1048576,
            'output_characters' => 20000,
            'list_entries' => 2000,
            'search_matches' => 200,
            'review_diff_characters' => 150000,
        ],

        // Paths tools may never change. Protected acceptance tests are also
        // replaced from the platform's copy before they run.
        'protected_paths' => ['tests/Acceptance', '.git', 'vendor', 'node_modules', '.env'],

        // Commands run in the workspace after the project is copied in and
        // before the driver starts, for example installing dependencies so
        // the tests can run. A failing command fails the run.
        'setup' => [],

        // Commands callers may run by name through the "run_command" tool.
        'commands' => [
            'tests' => ['command' => ['php', 'artisan', 'test'], 'timeout' => 600],
            'format' => ['command' => ['vendor/bin/pint', '--test'], 'timeout' => 300],
            'static_analysis' => ['command' => ['vendor/bin/phpstan', 'analyse', '--no-progress'], 'timeout' => 600],
        ],

        // The tools callers may use, by name.
        'tools' => [
            'read_file' => ReadFile::class,
            'list_files' => ListFiles::class,
            'search' => SearchFiles::class,
            'write_file' => WriteFile::class,
            'apply_patch' => ApplyPatch::class,
            'run_command' => RunCommand::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Context
    |--------------------------------------------------------------------------
    |
    | The application describes itself in `.builder/`: project.md, plus one
    | Markdown file per area in capabilities/ with its code paths,
    | behaviours and Effects. A run's agents get the project notes and the
    | files of the areas the change is about ("selective"). The other modes
    | are the comparison conditions for the context experiments: "none",
    | "flat" (every file) and "selective_without_effects".
    |
    */

    'context' => [
        'mode' => env('BUILDER_CONTEXT_MODE', 'selective'),

        // Context files larger than this are left out and reported.
        'max_file_bytes' => 65536,
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

    /*
    |--------------------------------------------------------------------------
    | Previews
    |--------------------------------------------------------------------------
    |
    | A preview runs the project with a feature request's change applied, in
    | its own workspace, and serves it at http://{host}.{domain}. Each preview
    | has its own host, so the customer app never shares the control plane's
    | origin, cookies or session. Point the domain's wildcard at this app
    | (*.localhost already resolves locally).
    |
    | Owners open a preview through a single-use grant that lives for
    | "grant_seconds" and becomes a cookie on the preview host that lasts
    | "session_minutes". Idle or expired previews are stopped by
    | `previews:reap`.
    |
    */

    'preview' => [
        'workspace_driver' => env('BUILDER_PREVIEW_WORKSPACE_DRIVER', 'local'),
        'domain' => env('BUILDER_PREVIEW_DOMAIN', 'preview.localhost'),
        'scheme' => env('BUILDER_PREVIEW_SCHEME', 'http'),
        'public_port' => env('BUILDER_PREVIEW_PORT', 8000),
        'cookie' => 'builder_preview',
        'grant_seconds' => 60,
        'session_minutes' => 120,
        'idle_minutes' => (int) env('BUILDER_PREVIEW_IDLE_MINUTES', 30),
        'max_minutes' => (int) env('BUILDER_PREVIEW_MAX_MINUTES', 240),
        'boot_seconds' => 30,
        // Address the app's web server binds to inside the workspace. Use
        // 0.0.0.0 for container drivers, which are reached at their own address.
        'listen_host' => env('BUILDER_PREVIEW_LISTEN_HOST', '127.0.0.1'),
        'request_timeout' => 60,

        // Ports the app's web server may listen on inside a workspace.
        'ports' => [20000, 20999],

        // Commands that prepare the project to run, after the change is applied.
        'setup' => [
            ['name' => 'Create .env', 'command' => ['cp', '.env.example', '.env'], 'timeout' => 30],
            ['name' => 'Install PHP dependencies', 'command' => ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'], 'timeout' => 900],
            ['name' => 'Generate app key', 'command' => ['php', 'artisan', 'key:generate', '--no-interaction'], 'timeout' => 60],
            ['name' => 'Create the database', 'command' => ['php', 'artisan', 'migrate', '--force', '--no-interaction'], 'timeout' => 120],
            ['name' => 'Install Node dependencies', 'command' => ['npm', 'ci', '--no-audit', '--no-fund'], 'timeout' => 600],
            ['name' => 'Build the frontend', 'command' => ['npm', 'run', 'build'], 'timeout' => 600],
        ],

        // Environment for the app's web server. APP_URL is set to the preview's URL.
        'environment' => [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'MAIL_MAILER' => 'log',
            'QUEUE_CONNECTION' => 'sync',
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
