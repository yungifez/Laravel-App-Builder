<?php

use App\Enums\Consequence;
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
    | Project Repositories
    |--------------------------------------------------------------------------
    |
    | The builder keeps a Git repository for every project under "root". A
    | registered project is imported into it as the first commit. Each change
    | the owner accepts becomes one commit, which can be reverted. Runs,
    | verifications and previews start from the commit a request was based
    | on. The owner is the author of each commit. Set "committer" to commit
    | as a named account instead of as the author. Customers can own these
    | repositories, so no default names this application.
    |
    */

    'projects' => [
        'root' => env('BUILDER_PROJECT_REPOSITORIES', storage_path('app/private/projects')),
        'branch' => env('BUILDER_PROJECT_BRANCH', 'main'),
        // The app a new project starts from. `php artisan projects:template`
        // puts the package below there. Until the folder exists, owners can
        // only bring in an app that already exists.
        'template' => env('BUILDER_TEMPLATE_PATH', storage_path('app/private/template')),
        // The current starter kit lives on its main branch; its tagged
        // releases are older Laravel versions.
        'template_package' => env('BUILDER_TEMPLATE_PACKAGE', 'laravel/vue-starter-kit:dev-main'),
        // The looks an owner picks from when starting a new app: one JSON
        // file each (colours, font, corner radius and feel), plus
        // contract.md, the design contract every new app starts with.
        'designs' => env('BUILDER_DESIGNS_PATH', resource_path('designs')),
        'committer' => [
            'name' => env('BUILDER_COMMITTER_NAME'),
            'email' => env('BUILDER_COMMITTER_EMAIL'),
        ],
        // Files every workspace of a project needs that are not part of its
        // code. The first workspace's setup makes them; they are saved
        // (encrypted) and every later workspace gets the same copy after its
        // setup, so any workspace can be thrown away.
        'workspace_files' => json_decode((string) env('BUILDER_WORKSPACE_FILES', '[".env"]'), true) ?: [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Design
    |--------------------------------------------------------------------------
    |
    | What the designer asks for when the owner wants the page they are
    | looking at made consistent. ":page" is the page's address. The owner
    | sees this in the conversation as their own request.
    |
    */

    'design' => [
        'consistency' => 'Make the :page page consistent. Parts that do the same job should share the same spacing, sizes, colours, corners and text styles, following the design notes. Keep what the page says and does.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    |
    | Publishing hands one commit to a host after the verification setup
    | and checks pass on that exact commit. The default host is Laravel
    | Cloud in our organization: the first publish creates the app's
    | private repository in our GitHub organization and its Cloud
    | application, so the owner publishes with one click. Until its token
    | and organization are set, and for owners who choose their own branch,
    | the "git" host pushes to the branch their hosting deploys from.
    |
    | A push never forces: when the branch has commits the project does not,
    | publishing stops. The app counts as online only once the host reports
    | it live (when it reports at all) and it answers at its address;
    | without an address a publish is only "sent". Local paths as remotes,
    | and local addresses, are for development and tests only.
    |
    */

    'publishing' => [
        'host' => env('BUILDER_PUBLISH_HOST', 'laravel_cloud'),

        // Laravel Cloud, in our organization. Each app gets a database in
        // "database_cluster" when one is named (create it once in Cloud).
        'laravel_cloud' => [
            'url' => env('LARAVEL_CLOUD_URL', 'https://cloud.laravel.com'),
            'token' => env('LARAVEL_CLOUD_API_TOKEN'),
            'region' => env('LARAVEL_CLOUD_REGION', 'us-east-2'),
            'database_cluster' => env('LARAVEL_CLOUD_DATABASE_CLUSTER'),
            'timeout' => 30,
        ],

        // Our GitHub organization, where a managed host deploys each app's
        // private repository from. The token needs to create repositories
        // and push to them; Cloud's GitHub app must see the organization.
        'github' => [
            'url' => env('BUILDER_GITHUB_API_URL', 'https://api.github.com'),
            'git_host' => env('BUILDER_GITHUB_GIT_HOST', 'github.com'),
            'organization' => env('BUILDER_GITHUB_ORGANIZATION'),
            'token' => env('BUILDER_GITHUB_TOKEN'),
        ],

        'allow_local_remotes' => (bool) env('BUILDER_PUBLISH_ALLOW_LOCAL_REMOTES', false),
        'push_timeout' => (int) env('BUILDER_PUBLISH_PUSH_TIMEOUT', 300),

        // After the push, the app's address is checked: first after
        // "settle_seconds", then every "interval_seconds" until every path
        // answers without an error and sign-in works, or "confirm_seconds"
        // have passed. Sign-in is tried with an account that cannot exist
        // at "sign_in_path"; an app without that page skips it.
        'confirm' => [
            'paths' => json_decode((string) env('BUILDER_PUBLISH_CHECK_PATHS', '["/up", "/"]'), true) ?: ['/'],
            'sign_in_path' => env('BUILDER_PUBLISH_SIGN_IN_PATH', '/login'),
            'settle_seconds' => (int) env('BUILDER_PUBLISH_SETTLE_SECONDS', 30),
            'interval_seconds' => (int) env('BUILDER_PUBLISH_CHECK_INTERVAL', 15),
            'confirm_seconds' => (int) env('BUILDER_PUBLISH_CONFIRM_SECONDS', 600),
            'timeout' => 10,
        ],

        // Errors the app raises online, read from the host every few
        // minutes (publishing:collect-errors). At most "pages" pages of logs
        // are read per check, and at most "kinds" kinds of error are kept.
        'errors' => [
            'pages' => (int) env('BUILDER_PUBLISH_ERROR_PAGES', 5),
            'kinds' => (int) env('BUILDER_PUBLISH_ERROR_KINDS', 20),
        ],

        // Hosts whose bills operators see on the Attention page: we pay for
        // the apps we host. Figures are kept for "spend_cache_minutes".
        'spend_hosts' => ['laravel_cloud'],
        'spend_cache_minutes' => (int) env('BUILDER_HOSTING_SPEND_CACHE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Tiers
    |--------------------------------------------------------------------------
    |
    | Two model roles plan and judge features: a frontier "planner" and an
    | independent "reviewer". A coding agent builds the change in between
    | (see "Coding Agents"). Each role names a laravel/ai provider
    | (config/ai.php) and model. Leave a provider empty to use the AI SDK
    | default, and a model empty to use that provider's default. Point both
    | roles at the same model to compare against a single stronger model; the
    | split is a strategy, not a dependency.
    |
    */

    'models' => [
        'planner' => [
            'provider' => env('BUILDER_PLANNER_PROVIDER'),
            'model' => env('BUILDER_PLANNER_MODEL'),
        ],
        'reviewer' => [
            'provider' => env('BUILDER_REVIEWER_PROVIDER'),
            'model' => env('BUILDER_REVIEWER_MODEL'),
        ],

        // Providers the planner and reviewer move on to, in order, when
        // their own cannot serve a call (down, rate-limited or out of
        // credit). Only providers with a key are tried, on their default
        // model. The coder never switches mid-change.
        'failover' => json_decode((string) env('BUILDER_MODEL_FAILOVER', '["openai", "anthropic"]'), true) ?: [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Prices
    |--------------------------------------------------------------------------
    |
    | Prices in US dollars per million tokens, keyed by model ID, used to put
    | a cost on planner and reviewer calls: {"model": {"input": 3, "output":
    | 15}}. Coding agents report their own cost. A call to a model without a
    | price has no cost, and telemetry counts it as unpriced.
    |
    */

    'prices' => json_decode((string) env('BUILDER_MODEL_PRICES', '{}'), true) ?: [],

    /*
    |--------------------------------------------------------------------------
    | Decisions
    |--------------------------------------------------------------------------
    |
    | Before a change is built, a cheap typed decision model (Jev, through the
    | laravel/ai "typesafe" provider) answers a few questions about the
    | request: how big it is, whether it is only a question, and whether it
    | touches permissions, stored data or deletes things. Each answer has a
    | confidence, and may only ever act at or above its threshold. For now
    | nothing acts (shadow mode): answers are kept and compared with what
    | happened, with `php artisan builder:decisions`. Without a key for any
    | listed provider, no decisions are made.
    |
    */

    'decisions' => [
        'providers' => json_decode((string) env('BUILDER_DECISION_PROVIDERS', '["typesafe"]'), true) ?: [],
        'timeout' => (int) env('BUILDER_DECISION_TIMEOUT', 10),

        // Decisions that would make a change cheaper need to be very sure;
        // decisions that would make it safer may act on a lower confidence.
        'thresholds' => [
            'complexity' => 0.9,
            'question' => 0.9,
            'permissions' => 0.6,
            'persisted_data' => 0.6,
            'destructive' => 0.6,
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
    | "sdk" driver plans and reviews with the model roles below, and builds
    | with a coding agent in the workspace (see "Coding Agents").
    |
    | One worker writes to a run at a time. Its lease lasts "lease_seconds"
    | and is renewed by every tool call, and every "heartbeat_seconds" while
    | a coding agent works. A lease that expires can be taken over by another
    | worker; the old worker's writes are then refused and its agent stopped.
    |
    */

    'construction' => [
        'driver' => env('BUILDER_CONSTRUCTION_DRIVER', 'scripted'),

        // Pictures an owner can attach to a request (a screenshot, a sketch,
        // a design), how large each may be, and the disk they are kept on.
        // The coder is given them to look at; they never enter the app.
        'images' => [
            'max' => (int) env('BUILDER_REQUEST_IMAGES_MAX', 4),
            'max_kilobytes' => (int) env('BUILDER_REQUEST_IMAGE_MAX_KB', 5120),
            'disk' => env('BUILDER_REQUEST_IMAGES_DISK', 'local'),
        ],

        'workspace_driver' => env('BUILDER_CONSTRUCTION_WORKSPACE_DRIVER', env('WORKSPACE_DRIVER', 'local')),

        'lease_seconds' => (int) env('BUILDER_RUN_LEASE_SECONDS', 300),

        'heartbeat_seconds' => (int) env('BUILDER_RUN_HEARTBEAT_SECONDS', 15),

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

        // How many product questions the planner may ask before building:
        // one by default, more once the owner asks for them (§7). A question
        // waits for the owner only when a wrong guess would touch one of
        // "ask_about" and could not be taken back later; otherwise the
        // change is built on the recommended option for the owner to review.
        'questions' => [
            'before_building' => (int) env('BUILDER_QUESTIONS_BEFORE_BUILDING', 1),
            'when_asked_for_more' => (int) env('BUILDER_QUESTIONS_WHEN_ASKED_FOR_MORE', 3),
            'ask_about' => json_decode((string) env('BUILDER_QUESTIONS_ASK_ABOUT', ''), true)
                ?: array_column(Consequence::cases(), 'value'),
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
        // the tests can run. A failing command fails the run. Set
        // BUILDER_CONSTRUCTION_SETUP to a JSON list of {name, command,
        // timeout} to configure it per deployment.
        'setup' => json_decode((string) env('BUILDER_CONSTRUCTION_SETUP', '[]'), true) ?: [],

        // Formatters run on the files a change touched, after the coder is
        // done and before the checks. Formatting is mechanical, so a change
        // is never sent back to a model for it. Each formatter gets the
        // changed files with its extensions appended; one that fails or is
        // missing is skipped. Set BUILDER_CONSTRUCTION_FORMATTERS to a JSON
        // list of {name, command, extensions, timeout} to configure it.
        'formatters' => json_decode((string) env('BUILDER_CONSTRUCTION_FORMATTERS', ''), true) ?: [
            ['name' => 'PHP', 'command' => ['vendor/bin/pint'], 'extensions' => ['php'], 'timeout' => 120],
            ['name' => 'Frontend', 'command' => ['npx', '--no-install', 'vp', 'fmt'], 'extensions' => ['ts', 'vue', 'js', 'mjs', 'css', 'json', 'md'], 'timeout' => 120],
        ],

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
    | Coding Agents
    |--------------------------------------------------------------------------
    |
    | The "sdk" construction driver builds with a coding agent SDK working in
    | the workspace, through the Node runner in resources/agent-runner. The
    | agents are tried in "order": the next one is used only when a provider
    | cannot serve the task (down, overloaded, rate-limited, out of quota or
    | refusing the credentials), never because a change failed. An agent whose
    | provider failed "circuit.failures" times is tried last for
    | "circuit.minutes". The reviewer always uses the other provider from the
    | one that built the change.
    |
    */

    'agents' => [
        'order' => ['claude', 'codex'],

        'runner' => [
            'node' => env('BUILDER_AGENT_NODE', 'node'),
            'path' => env('BUILDER_AGENT_RUNNER', resource_path('agent-runner/run.mjs')),
        ],

        'adapters' => [
            'claude' => ['provider' => 'anthropic', 'model' => env('BUILDER_CLAUDE_AGENT_MODEL')],
            // Codex's own sandbox needs Linux user namespaces, which most
            // containers do not allow. Where the workspace is already the
            // boundary (a container), set BUILDER_CODEX_SANDBOX to
            // "danger-full-access", as the Claude agent runs.
            'codex' => [
                'provider' => 'openai',
                'model' => env('BUILDER_CODEX_AGENT_MODEL'),
                'sandbox' => env('BUILDER_CODEX_SANDBOX', 'workspace-write'),
            ],
        ],

        'max_turns' => (int) env('BUILDER_AGENT_MAX_TURNS', 80),
        'max_budget_usd' => (float) env('BUILDER_AGENT_MAX_BUDGET_USD', 5),

        'circuit' => [
            'failures' => 3,
            'minutes' => 10,
        ],

        'reviewers' => [
            'anthropic' => ['provider' => 'openai', 'model' => env('BUILDER_OPENAI_REVIEWER_MODEL')],
            'openai' => ['provider' => 'anthropic', 'model' => env('BUILDER_ANTHROPIC_REVIEWER_MODEL')],
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

        // Where a workspace gets its copy of the project's notes. The notes
        // live in our database, never in the app's repository; the coding
        // agent reads and updates them here.
        'directory' => env('BUILDER_NOTES_DIRECTORY', '.product-notes'),

        // Context files larger than this are left out and reported.
        'max_file_bytes' => 65536,

        // The quick check reports files under these paths that no area
        // describes, except the "undescribed" patterns: framework plumbing
        // every Laravel app has.
        'described_paths' => json_decode((string) env('BUILDER_DESCRIBED_PATHS', '["app/", "routes/", "resources/js/pages/"]'), true) ?: [],
        'undescribed' => [
            'app/Http/Controllers/Controller.php',
            'app/Providers/*',
            'routes/console.php',
        ],

        // Kept changes that were about one area and also changed another
        // make a "history" Effect once this many agree, read from this many
        // of the latest kept changes.
        'history' => [
            'min_changes' => (int) env('BUILDER_HISTORY_MIN_CHANGES', 2),
            'window' => (int) env('BUILDER_HISTORY_WINDOW', 50),
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
        'workspace_driver' => env('BUILDER_VERIFICATION_DRIVER', env('WORKSPACE_DRIVER', 'local')),

        // Checks go to their own queue when one is named; give it its own
        // worker so they do not wait behind other changes being built.
        'queue' => env('BUILDER_VERIFICATION_QUEUE'),

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

        // The check that runs the project's whole test suite. When it passes,
        // an area with its own tests counts as verified in the review. Its
        // "report" is the JUnit file it writes: a verify item counts as
        // tested only when the test named for it is in that report, passed.
        'suite_check' => 'Tests',

        // Where the tests that the suite check runs live, and how their
        // file names end. A test file elsewhere, or named otherwise (a
        // Vitest file under tests/, for example), is not evidence, because
        // no check runs it.
        'suite_paths' => json_decode((string) env('BUILDER_SUITE_PATHS', '["tests/"]'), true) ?: ['tests/'],
        'suite_suffixes' => json_decode((string) env('BUILDER_SUITE_SUFFIXES', '["Test.php"]'), true) ?: ['Test.php'],

        // Whether a change built by a model must have a test for each of the
        // brief's verify items (its acceptance criteria). A missing test is a
        // blocking finding, so the coder is sent back to add it.
        'require_verify_tests' => (bool) env('BUILDER_REQUIRE_VERIFY_TESTS', true),

        // Whether the lines a change adds are scanned for common safety
        // mistakes (unescaped output, raw HTML, queries built from values,
        // records open to any field, committed .env files). Each one found
        // is a blocking finding, so the coder is sent back to fix it.
        'safety_scan' => (bool) env('BUILDER_SAFETY_SCAN', true),

        // Screen checks (direction 26). The screen lines a change adds are
        // checked for colours written out (hex values, rgb() and the like)
        // instead of taken from the app's theme, and for pictures without
        // an alt description. Each one is a blocking finding, so the coder
        // is sent back to fix it.
        'design_scan' => (bool) env('BUILDER_DESIGN_SCAN', true),

        // Test impact evidence (direction 22). When the suite check passes,
        // the suite runs once more with code coverage, and the test list is
        // read with its groups, to record which tests ran which code files.
        // A test in a "behavior:<key>" group proves that behaviour. The run
        // never changes the checks' result; without a coverage driver it
        // records nothing. The command writes the condensed coverage
        // ("report") and PHPUnit's test list ("listing"), using PHPUnit's
        // documented report formats only.
        'test_map' => [
            'enabled' => (bool) env('BUILDER_TEST_MAP', true),
            'command' => ['sh', '-c', implode(' && ', [
                'rm -rf storage/logs/test-map',
                'mkdir -p storage/logs/test-map',
                'php -d pcov.enabled=1 artisan test --coverage-xml=storage/logs/test-map/coverage > /dev/null',
                'test -f storage/logs/test-map/coverage/index.xml',
                '(php artisan test --list-tests-xml=storage/logs/test-map/tests.xml > /dev/null || true)',
                '{ pwd; grep -o \'<project source="[^"]*"\' storage/logs/test-map/coverage/index.xml; grep -rhoE \'<file name="[^"]*" path="[^"]*"|<line nr="[0-9]+"|covered by="[^"]*"\' --include=\'*.php.xml\' storage/logs/test-map/coverage || true; } > storage/logs/test-map/covered.txt',
            ])],
            'timeout' => 900,
            'report' => 'storage/logs/test-map/covered.txt',
            'listing' => 'storage/logs/test-map/tests.xml',

            // Code most tests run (the user model, middleware, providers) is
            // the app's foundation: it would tie every area to every other,
            // so it makes no Effect, and changing it is a broad change. A
            // file is foundation when more than this share of the tests run
            // it, once the suite has at least "foundation_min_tests" tests.
            'foundation_share' => (float) env('BUILDER_FOUNDATION_SHARE', 0.5),
            'foundation_min_tests' => (int) env('BUILDER_FOUNDATION_MIN_TESTS', 10),
        ],

        'checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test', '--log-junit=storage/logs/junit.xml'], 'timeout' => 600, 'report' => 'storage/logs/junit.xml'],
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
        // Start a preview of each change as soon as it is built, while it
        // is checked and reviewed. Previews go to their own queue when one
        // is named; give it its own worker so they start in parallel.
        'automatic' => (bool) env('BUILDER_PREVIEW_AUTOMATIC', true),
        'queue' => env('BUILDER_PREVIEW_QUEUE'),
        'workspace_driver' => env('BUILDER_PREVIEW_WORKSPACE_DRIVER', env('WORKSPACE_DRIVER', 'local')),
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

        // Point-and-edit. An editable preview runs the locator after setup,
        // which marks each element with the template line it comes from,
        // then runs the rebuild steps. The same steps run again after each
        // visual edit.
        'locator' => [
            'node' => env('BUILDER_AGENT_NODE', 'node'),
            'path' => env('BUILDER_PREVIEW_LOCATOR', resource_path('preview-tools/locate-sources.mjs')),
            'directories' => ['resources/js'],
        ],
        'rebuild' => [
            ['name' => 'Build the frontend', 'command' => ['npm', 'run', 'build'], 'timeout' => 600],
        ],
        'overlay' => resource_path('preview-tools/overlay.js'),

        // Environment for the app's web server. APP_URL is set to the preview's URL.
        'environment' => [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'MAIL_MAILER' => 'log',
            'QUEUE_CONNECTION' => 'sync',
        ],
    ],

    'notifications' => [
        // Also email the owner when a change is ready, has a question or
        // did not work. They are always told in the builder itself.
        'email' => (bool) env('BUILDER_NOTIFY_BY_EMAIL', false),
    ],

    'generators' => [

        'reference' => [
            // Directory holding manifest.json and the patches it names.
            // Relative paths are resolved from the application's base path.
            'path' => env('BUILDER_REFERENCE_SOLUTIONS'),
        ],

    ],

];
