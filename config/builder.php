<?php

use App\Enums\Consequence;
use App\Features\CodeShortcuts;
use App\Runs\Tools\ApplyPatch;
use App\Runs\Tools\ListFiles;
use App\Runs\Tools\ReadFile;
use App\Runs\Tools\RunCommand;
use App\Runs\Tools\SearchFiles;
use App\Runs\Tools\WriteFile;

// Install from the app's lock file. An app that keeps none installs
// without one, and writes none: a new lock file would show up in the
// owner's change.
$installNodeDependencies = ['sh', '-c', 'if [ -f package-lock.json ]; then exec npm ci --no-audit --no-fund; fi; exec npm install --no-audit --no-fund --no-package-lock'];

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
        // Where every project's repository is kept off this server's disk,
        // for hosts whose disks do not last (Laravel Cloud). Each server
        // keeps a working copy under "root", saves after every change and
        // fetches when another server saved a newer one.
        //  - "github": a private repository per project, named
        //    "<prefix>-<project id>", in the organization below (or the
        //    publishing organization), with the publishing GitHub token.
        //  - "disk": one Git bundle per project, "<prefix>/<id>.bundle", on
        //    a filesystem disk from config/filesystems.php.
        //  - empty: repositories stay only under "root".
        'store' => [
            'driver' => env('BUILDER_PROJECT_STORE'),
            'organization' => env('BUILDER_PROJECT_STORE_ORGANIZATION'),
            'disk' => env('BUILDER_PROJECT_STORE_DISK'),
            'prefix' => env('BUILDER_PROJECT_STORE_PREFIX', 'code'),
            // A server removes its copy of a project not used this long, so
            // copies cannot fill its small disk; the store keeps the code.
            'idle_minutes' => (int) env('BUILDER_PROJECT_STORE_IDLE_MINUTES', 360),
        ],
        // The app a new project starts from. `php artisan projects:template`
        // puts the package below there. Until the folder exists, owners can
        // only bring in an app that already exists.
        'template' => env('BUILDER_TEMPLATE_PATH', storage_path('app/private/template')),
        // The current starter kit lives on its main branch; its tagged
        // releases are older Laravel versions.
        'template_package' => env('BUILDER_TEMPLATE_PACKAGE', 'laravel/vue-starter-kit:dev-main'),
        // Build the first version of a new app from the owner's sentence,
        // as its first change, so they see their app and not the template's
        // welcome page. Each new app then spends model calls at once.
        'first_version' => (bool) env('BUILDER_FIRST_VERSION', true),
        // The looks an owner picks from when starting a new app: one JSON
        // file each (colours, font, corner radius and feel), plus
        // contract.md, the design contract every new app starts with.
        'designs' => env('BUILDER_DESIGNS_PATH', resource_path('designs')),
        // Ready-made ideas an owner can start a new app from: one JSON file
        // each (name, purpose, look, and what the first version includes).
        // The owner sees and can untick every item before starting.
        'starters' => env('BUILDER_STARTERS_PATH', resource_path('starters')),
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
    | Services
    |--------------------------------------------------------------------------
    |
    | Outside services an owner can connect their app to by pasting its keys.
    | The keys are kept encrypted with the project and given to the app as
    | environment variables: in its previews and where it is published.
    | Each field's `rules` check what is pasted; `environment` adds fixed
    | variables once connected. `request` is the change the owner sees
    | asked for; `guidance` tells the agent how.
    |
    */

    'services' => [
        'payments' => [
            'name' => 'Take payments',
            'provider' => 'Stripe',
            'about' => 'Customers pay by card. Money goes to your Stripe account.',
            'keys_at' => 'https://dashboard.stripe.com/test/apikeys',
            'fields' => [
                'STRIPE_KEY' => ['label' => 'Publishable key', 'rules' => ['regex:/^pk_(test|live)_[A-Za-z0-9]+$/'], 'hint' => 'It starts with pk_test_'],
                'STRIPE_SECRET' => ['label' => 'Secret key', 'rules' => ['regex:/^sk_(test|live)_[A-Za-z0-9]+$/'], 'hint' => 'It starts with sk_test_', 'secret' => true],
            ],
            'environment' => [],
            'request' => 'Let customers pay online by card with Stripe.',
            'guidance' => 'Payments: take them with Stripe through Laravel Cashier (laravel/cashier). Read the keys from the STRIPE_KEY and STRIPE_SECRET environment variables through config/cashier.php; never write key values into code, tests or .env.example (list the names there with empty values). In tests, never call Stripe: fake the parts that would.',
        ],
        'email' => [
            'name' => 'Send email',
            'provider' => 'Resend',
            'about' => 'Your app sends email, such as receipts and reminders, from your own address.',
            'keys_at' => 'https://resend.com/api-keys',
            'fields' => [
                'RESEND_API_KEY' => ['label' => 'API key', 'rules' => ['regex:/^re_[A-Za-z0-9_]+$/'], 'hint' => 'It starts with re_', 'secret' => true],
                'MAIL_FROM_ADDRESS' => ['label' => 'Send from', 'rules' => ['email'], 'hint' => 'An address on a domain you added to Resend'],
            ],
            'environment' => ['MAIL_MAILER' => 'resend'],
            'request' => 'Send the app\'s emails for real, through Resend.',
            'guidance' => 'Email: send it with Laravel\'s mail through the resend mailer (install resend/resend-php, which the mailer needs). The key comes from the RESEND_API_KEY environment variable and MAIL_MAILER is set to resend where the app runs; never write the key into code, tests or .env.example. In tests, use Mail::fake().',
        ],
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

        // Forge, in our organization, on Hetzner servers it manages. Many
        // apps share a server, each with its own site, database and free
        // on-forge.com address. A server named with "server_prefix" takes
        // up to "sites_per_server" apps; when all are full, a new one is
        // made with the Hetzner credential, network, region and size IDs
        // from Forge (leave them empty to add servers by hand). The token
        // is services.forge.token.
        'forge' => [
            'organization' => env('FORGE_ORGANIZATION'),
            'server_prefix' => env('FORGE_SERVER_PREFIX', 'apps'),
            'sites_per_server' => (int) env('FORGE_SITES_PER_SERVER', 15),
            'credential' => env('FORGE_HETZNER_CREDENTIAL'),
            'network' => env('FORGE_HETZNER_NETWORK'),
            'region' => env('FORGE_HETZNER_REGION'),
            'size' => env('FORGE_HETZNER_SIZE'),
            'php_version' => env('FORGE_PHP_VERSION', 'php84'),
            'database_type' => env('FORGE_DATABASE_TYPE', 'postgres18'),
            'server_wait_seconds' => (int) env('FORGE_SERVER_WAIT_SECONDS', 1200),
            'wait_seconds' => (int) env('FORGE_WAIT_SECONDS', 120),

            // Where copies of each app's database go: a storage provider's
            // ID from Forge. A copy is made each night, kept for
            // "backup_retention" copies, and before every release that
            // changes how the app stores information.
            'backup_storage' => env('FORGE_BACKUP_STORAGE'),
            'backup_retention' => (int) env('FORGE_BACKUP_RETENTION', 7),
            'backup_wait_seconds' => (int) env('FORGE_BACKUP_WAIT_SECONDS', 600),
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
    | 15}}. Add "cached_input" for input the provider reads back from its
    | cache at a lower price; without it, cached input costs as much as
    | fresh input. Claude's coding agent reports its own cost; Codex's is
    | priced here. A call to a model without a price has no cost, and
    | telemetry counts it as unpriced.
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
    | with a coding agent in the workspace (see "Coding Agents"). The
    | "worker" driver plans and reviews the same way, but waits for a worker
    | outside, such as the owner's own coding agent, to hand the change back.
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
            'repairs' => (int) env('BUILDER_RUN_MAX_REPAIRS', 4),
            // Times a change that passed its checks is reviewed again after
            // the review stopped on our side, before the run fails.
            'review_restarts' => (int) env('BUILDER_RUN_MAX_REVIEW_RESTARTS', 3),
            // What all changes together may spend on AI in one day, in US
            // dollars; a run stops once today's spend reaches it. 0 turns
            // the limit off.
            'daily_usd' => (float) env('BUILDER_DAILY_SPEND_USD', 10),
        ],

        // What the planner sees besides the request: the file list (up to
        // "max_files"), the app's addresses and the code that handles each
        // (up to "max_routes"), and these files when the project has them.
        // The addresses let the planner name the right files, so the
        // coding agent spends less time searching for them.
        'planning' => [
            'max_files' => 800,
            'max_routes' => 300,
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
        // replaced from the platform's copy before they run. The files that
        // decide how the app's tests and type checks run, and its CI, are
        // here too, so a change cannot pass its checks by changing them
        // (direction 33); changing them is a decision for a person.
        'protected_paths' => ['tests/Acceptance', '.git', 'vendor', 'node_modules', '.env', 'phpunit.xml', 'phpunit.xml.dist', 'tests/Pest.php', 'phpstan.neon', 'phpstan.neon.dist', '.github'],

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

        // Checks the coding agent runs itself before it finishes, by name
        // from verification.checks. Only quick ones: each failure found
        // after the agent finishes costs a whole repair pass, while these
        // take seconds. The rest still run on their own afterwards.
        'self_checks' => json_decode((string) env('BUILDER_CONSTRUCTION_SELF_CHECKS', ''), true) ?: ['Static analysis'],

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
    | Codex can work on the operator's own ChatGPT plan instead of the API
    | key: set BUILDER_CODEX_USE_API_KEY to false, put "codex" first in
    | BUILDER_AGENT_ORDER, and sign Codex in once with "codex login" using
    | the same CODEX_HOME as BUILDER_CODEX_HOME.
    |
    */

    'agents' => [
        'order' => array_map(trim(...), explode(',', (string) env('BUILDER_AGENT_ORDER', 'claude,codex'))),

        'runner' => [
            'node' => env('BUILDER_AGENT_NODE', 'node'),
            'path' => env('BUILDER_AGENT_RUNNER', resource_path('agent-runner/run.mjs')),
        ],

        'adapters' => [
            // "light_model" takes small, well-defined tasks, such as a
            // background tidy-up; unset, the usual model takes them. "effort"
            // sets how hard the model thinks (low, medium, high and so on),
            // and "light_effort" does the same for light tasks. Less effort
            // costs less; unset, the provider's default is used.
            'claude' => [
                'provider' => 'anthropic',
                'model' => env('BUILDER_CLAUDE_AGENT_MODEL'),
                'light_model' => env('BUILDER_CLAUDE_AGENT_LIGHT_MODEL'),
                'effort' => env('BUILDER_CLAUDE_AGENT_EFFORT'),
                'light_effort' => env('BUILDER_CLAUDE_AGENT_LIGHT_EFFORT'),
            ],
            // Codex's own sandbox needs Linux user namespaces, which most
            // containers do not allow. Where the workspace is already the
            // boundary (a container), set BUILDER_CODEX_SANDBOX to
            // "danger-full-access", as the Claude agent runs.
            'codex' => [
                'provider' => 'openai',
                'model' => env('BUILDER_CODEX_AGENT_MODEL'),
                'light_model' => env('BUILDER_CODEX_AGENT_LIGHT_MODEL'),
                'effort' => env('BUILDER_CODEX_AGENT_EFFORT'),
                'light_effort' => env('BUILDER_CODEX_AGENT_LIGHT_EFFORT'),
                'sandbox' => env('BUILDER_CODEX_SANDBOX', 'workspace-write'),
                // Set to false to use what "codex login" saved, such as a ChatGPT
                // plan, kept where "home" says (Codex's own default if null).
                'use_api_key' => (bool) env('BUILDER_CODEX_USE_API_KEY', true),
                'home' => env('BUILDER_CODEX_HOME'),
            ],
        ],

        'max_turns' => (int) env('BUILDER_AGENT_MAX_TURNS', 80),
        'max_budget_usd' => (float) env('BUILDER_AGENT_MAX_BUDGET_USD', 5),

        // A repair of one problem a check can judge (one failing test, one
        // static analysis error) goes to each agent's light_model. After a
        // light repair that did not pass, the next goes to the usual model.
        'light_repairs' => (bool) env('BUILDER_AGENT_LIGHT_REPAIRS', true),

        // After this many repairs that did not pass, the next repair goes to
        // the next agent in "order", which starts fresh with the whole brief.
        'escalate_after' => (int) env('BUILDER_AGENT_ESCALATE_AFTER', 2),

        'circuit' => [
            'failures' => 3,
            'minutes' => 10,
        ],

        // Whoever runs a coding agent, ours or the owner's own, reaches the
        // change's tools (routes/ai.php) with a token that opens that one
        // change for "minutes", and makes at most "per_minute" calls. A
        // change it hands back is at most "max_patch_kb" long.
        'workers' => [
            'minutes' => (int) env('BUILDER_WORKER_MINUTES', 240),
            // How long the owner's tool stays connected to a whole app.
            'project_days' => (int) env('BUILDER_WORKER_PROJECT_DAYS', 30),
            'per_minute' => (int) env('BUILDER_WORKER_PER_MINUTE', 60),
            'max_patch_kb' => (int) env('BUILDER_WORKER_MAX_PATCH_KB', 512),
            // What the owner's tool may run on its change in our workspace
            // (try_change), as the start of the command, and for how long.
            'try_commands' => json_decode((string) env('BUILDER_WORKER_TRY_COMMANDS', ''), true) ?: [
                ['php', 'artisan'],
                ['vendor/bin/pest'],
                ['vendor/bin/phpunit'],
                ['vendor/bin/pint'],
                ['vendor/bin/phpstan'],
                ['npm', 'run'],
            ],
            'try_seconds' => (int) env('BUILDER_WORKER_TRY_SECONDS', 300),
            // check_status holds its answer up to this long while the change
            // is with us, and answers as soon as anything changes. Fewer polls
            // spend fewer of the owner's tokens; keep it under the proxy's
            // request timeout.
            'status_wait_seconds' => (int) env('BUILDER_WORKER_STATUS_WAIT_SECONDS', 45),
        ],

        // Our coding agents reach their model through the control plane:
        // each run gets a token that opens the gateway for that run only,
        // and the gateway sends the call on with the real key. So a key
        // never goes into a workspace, where the agent has a shell. "url" is
        // where the agent runner reaches the control plane. A run's token
        // stops working after "max_requests" calls or "max_output_tokens"
        // written, whatever the agent was told.
        'gateway' => [
            'enabled' => (bool) env('BUILDER_MODEL_GATEWAY', false),
            'url' => env('BUILDER_MODEL_GATEWAY_URL', 'http://laravel.test'),
            'max_requests' => (int) env('BUILDER_MODEL_GATEWAY_MAX_REQUESTS', 2000),
            'max_output_tokens' => (int) env('BUILDER_MODEL_GATEWAY_MAX_OUTPUT_TOKENS', 2_000_000),
        ],

        'reviewers' => [
            'anthropic' => ['provider' => 'openai', 'model' => env('BUILDER_OPENAI_REVIEWER_MODEL')],
            'openai' => ['provider' => 'anthropic', 'model' => env('BUILDER_ANTHROPIC_REVIEWER_MODEL')],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontends
    |--------------------------------------------------------------------------
    |
    | The ways a Laravel app draws its screens. The builder works on any of
    | them: an app is the first stack whose packages its composer.json
    | ("composer") and package.json ("npm") require, so the last one, which
    | requires nothing, takes every other app. "pages" lists the folders its
    | screens live in, which the notes are expected to describe.
    |
    */

    'frontends' => [
        'inertia-vue' => [
            'label' => 'Inertia with Vue',
            'composer' => ['inertiajs/inertia-laravel'],
            'npm' => ['@inertiajs/vue3'],
            'pages' => ['resources/js/pages/', 'resources/js/Pages/'],
        ],
        'inertia-react' => [
            'label' => 'Inertia with React',
            'composer' => ['inertiajs/inertia-laravel'],
            'npm' => ['@inertiajs/react'],
            'pages' => ['resources/js/pages/', 'resources/js/Pages/'],
        ],
        'inertia-svelte' => [
            'label' => 'Inertia with Svelte',
            'composer' => ['inertiajs/inertia-laravel'],
            'npm' => ['@inertiajs/svelte'],
            'pages' => ['resources/js/pages/', 'resources/js/Pages/'],
        ],
        'livewire' => [
            'label' => 'Livewire',
            'composer' => ['livewire/livewire'],
            'pages' => ['resources/views/'],
        ],
        'blade' => [
            'label' => 'Blade views',
            'pages' => ['resources/views/'],
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
        // every Laravel app has. The folders of the app's screens are added
        // from its frontend (see "frontends").
        'described_paths' => json_decode((string) env('BUILDER_DESCRIBED_PATHS', '["app/", "routes/"]'), true) ?: [],
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

        // How many times checks stopped by a problem on our side (no runner
        // took a command, a workspace could not start) are run again before
        // the run stops and says it is our fault. These never count as a
        // repair, and the coder never hears of them.
        'retries' => (int) env('BUILDER_VERIFICATION_RETRIES', 2),

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
            // The database server the app asks for in .env, private to the
            // workspace (MySQL, MariaDB or PostgreSQL; SQLite needs none).
            ['name' => 'Start the database', 'command' => ['sh', '-c', (string) file_get_contents(resource_path('preview-tools/start-database.sh'))], 'timeout' => 120],
            ['name' => 'Install Node dependencies', 'command' => $installNodeDependencies, 'timeout' => 600, 'needs' => 'package.json'],
            ['name' => 'Generate route helpers', 'command' => ['php', 'artisan', 'wayfinder:generate', '--with-form'], 'timeout' => 120, 'needs' => 'vendor/laravel/wayfinder'],
            // Tests that open a page need the built screens, as in the
            // starter kits' own CI; without them every such test fails on
            // a missing Vite manifest and blames the app for our setup.
            ['name' => 'Build the screens', 'command' => ['npm', 'run', 'build'], 'timeout' => 600, 'needs' => 'package.json'],
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

        // Test checks. A PHP test the change adds that starts Node itself
        // (to render a screen, most often) is a blocking finding: Laravel
        // apps test screens with their frontend's own test helpers or Pest
        // browser tests.
        'test_scan' => (bool) env('BUILDER_TEST_SCAN', true),

        // Shortcuts in the app's PHP code that cost the owner later: errors
        // caught and ignored, and the database asked once per row. The PHP
        // files a change touched are read by the Sloppy analyser
        // (heyosseus/sloppy), which runs without a model, so the same code
        // always gets the same answer. It ships in the box image, never in
        // the app. What it finds is kept on the verification and never
        // holds the change back: the owner is not kept waiting for it.
        // Where the analyser is not installed, nothing is read and nothing
        // is said. The changed files are added as --path options.
        //
        // After the owner keeps a change, "triage" asks the decision model
        // (see "decisions"), in the background, whether each shortcut found
        // is a real problem, with the whole file to read. It waits
        // "delay_minutes" so it does not compete with the owner's next
        // change. A "yes" at or above "threshold" counts as a real problem.
        //
        // The real ones are then fixed by "tidy": a change the app asks for
        // itself, built by the coding agents' light model on a small budget
        // and, when that fails or leaves a shortcut, by the usual model. It
        // starts only while the owner has no change being built, or built
        // and touched in the last "idle_minutes", checking again every
        // "wait_minutes" for a day. It is kept on its own once it passes the
        // checks and the shortcuts are gone or explained in a comment. The
        // owner can undo it.
        'shortcuts' => [
            'enabled' => (bool) env('BUILDER_SHORTCUT_SCAN', true),
            'command' => ['sh', '-c', implode(' ', [
                'test -f '.env('BUILDER_SHORTCUT_TOOL', '/opt/sloppy/sloppy.phar'),
                '&& mkdir -p storage/logs',
                '&& php '.env('BUILDER_SHORTCUT_TOOL', '/opt/sloppy/sloppy.phar').' scan --project=. --no-baseline --format=json --fail-on=never',
                '--min-confidence='.(int) env('BUILDER_SHORTCUT_MIN_CONFIDENCE', 80),
                implode(' ', array_map(fn (string $rule) => "--rule={$rule}", CodeShortcuts::rules())),
                '"$@" > storage/logs/shortcuts.json',
            ]), 'shortcuts'],
            'timeout' => 120,
            'report' => 'storage/logs/shortcuts.json',
            'triage' => [
                'enabled' => (bool) env('BUILDER_SHORTCUT_TRIAGE', true),
                'delay_minutes' => (int) env('BUILDER_SHORTCUT_TRIAGE_DELAY_MINUTES', 10),
                'threshold' => (float) env('BUILDER_SHORTCUT_TRIAGE_THRESHOLD', 0.5),
                'timeout' => (int) env('BUILDER_SHORTCUT_TRIAGE_TIMEOUT', 30),
            ],
            'tidy' => [
                'enabled' => (bool) env('BUILDER_SHORTCUT_TIDY', true),
                'max_budget_usd' => (float) env('BUILDER_SHORTCUT_TIDY_MAX_BUDGET_USD', 1),
                'wait_minutes' => (int) env('BUILDER_SHORTCUT_TIDY_WAIT_MINUTES', 15),
                'idle_minutes' => (int) env('BUILDER_SHORTCUT_TIDY_IDLE_MINUTES', 120),
            ],
        ],

        // Screens on phones, tablets and computers (direction 26). When the
        // checks pass and the change touches a screen, the app is built,
        // served and opened in a browser at each width. On a page whose
        // screen file the change touched, words or controls cut off at the
        // edge, sideways scrolling, controls too small to tap (WCAG 2.2 AA)
        // and script errors are blocking findings. The command runs in the
        // workspace and writes the measuring tool's JSON to "report"; where
        // the tool is not installed (only the box image has it), nothing is
        // measured and nothing is said.
        'screens' => [
            'enabled' => (bool) env('BUILDER_SCREEN_CHECK', true),
            'command' => ['sh', '-c', implode(' && ', [
                'rm -rf storage/logs/screens',
                'mkdir -p storage/logs/screens',
                'test -f '.env('BUILDER_SCREEN_CHECK_TOOL', '/opt/screen-check/check.mjs'),
                'npm run build > storage/logs/screens/build.log 2>&1',
                'touch database/database.sqlite',
                'php artisan migrate --force > storage/logs/screens/migrate.log 2>&1',
                // Records to show, from the app's own seeder, when it has one.
                '(php artisan db:seed --force > storage/logs/screens/seed.log 2>&1 || true)',
                'node '.env('BUILDER_SCREEN_CHECK_TOOL', '/opt/screen-check/check.mjs').' > storage/logs/screens/report.json',
            ])],
            'timeout' => 600,
            'report' => 'storage/logs/screens/report.json',
            // Pictures of the touched screens at each width, for the owner
            // to see in the change's proof, and how many screens get them.
            'shots_disk' => env('BUILDER_SCREEN_SHOTS_DISK', 'local'),
            'shots_max' => (int) env('BUILDER_SCREEN_SHOTS_MAX', 3),
        ],

        // Test impact evidence (direction 22). The suite check runs with
        // code coverage (if that run fails, the plain "Tests" command runs
        // and decides the check), and the test list is read with its
        // groups, to record which tests ran which code files.
        // A test in a "behavior:<key>" group proves that behaviour. The run
        // never changes the checks' result; without a coverage driver it
        // records nothing. The command writes the condensed coverage
        // ("report"), PHPUnit's test list ("listing") and, from the Clover
        // report of the same run, each line that can run with how many
        // times it ran ("lines"), using PHPUnit's documented report formats
        // only. The lines say which of a change's new lines no test ran,
        // and which only its own tests ran. The same run records what
        // each request of a test did ("traces" below), so nothing more
        // runs for it.
        'test_map' => [
            'enabled' => (bool) env('BUILDER_TEST_MAP', true),
            'command' => ['sh', '-c', implode(' && ', [
                'rm -rf storage/logs/test-map',
                'mkdir -p storage/logs/test-map',
                ...(env('BUILDER_TRACES', true) ? [implode(' ', [
                    'if [ -f '.env('BUILDER_TRACE_RECORDER', '/opt/trace-recorder').'/prepend.php ]; then',
                    'mkdir -p storage/logs/test-map/trace',
                    '&& printf \'auto_prepend_file=%s\n\' '.env('BUILDER_TRACE_RECORDER', '/opt/trace-recorder').'/prepend.php > storage/logs/test-map/trace/prepend.ini',
                    '&& export PHP_INI_SCAN_DIR=":$PWD/storage/logs/test-map/trace" TRACE_RECORDER_DIR="$PWD/storage/logs/test-map/trace";',
                    'fi',
                ])] : []),
                // The suite check's own run: its report and output are the
                // check's, as for the plain "Tests" command.
                'php -d pcov.enabled=1 artisan test --log-junit=storage/logs/junit.xml --coverage-xml=storage/logs/test-map/coverage --coverage-clover=storage/logs/test-map/clover.xml',
                'test -f storage/logs/test-map/coverage/index.xml',
                '(php artisan test --list-tests-xml=storage/logs/test-map/tests.xml > /dev/null || true)',
                '{ pwd; grep -o \'<project source="[^"]*"\' storage/logs/test-map/coverage/index.xml; grep -rhoE \'<file name="[^"]*" path="[^"]*"|<line nr="[0-9]+"|covered by="[^"]*"\' --include=\'*.php.xml\' storage/logs/test-map/coverage || true; } > storage/logs/test-map/covered.txt',
                '{ pwd; grep -oE \'<file name="[^"]*"|<line num="[0-9]+" type="stmt" count="[0-9]+"\' storage/logs/test-map/clover.xml || true; } > storage/logs/test-map/lines.txt',
            ])],
            'timeout' => 900,
            'report' => 'storage/logs/test-map/covered.txt',
            'listing' => 'storage/logs/test-map/tests.xml',
            'lines' => 'storage/logs/test-map/lines.txt',

            // Code most tests run (the user model, middleware, providers) is
            // the app's foundation: it would tie every area to every other,
            // so it makes no Effect, and changing it is a broad change. A
            // file is foundation when more than this share of the tests run
            // it, once the suite has at least "foundation_min_tests" tests.
            'foundation_share' => (float) env('BUILDER_FOUNDATION_SHARE', 0.5),
            'foundation_min_tests' => (int) env('BUILDER_FOUNDATION_MIN_TESTS', 10),
        ],

        // What the app did while its tests used it (direction 32). A
        // recorder in the box image (BUILDER_TRACE_RECORDER, never added to
        // the app) is loaded into the coverage run above by PHP's
        // auto_prepend_file
        // and a copy of Laravel's package list, both kept under
        // storage/logs. It writes one line per request to "report": its
        // queries, transactions, and what it queued and sent, each with
        // the line of the app's code it came from. Where the recorder is
        // missing, nothing is recorded. A lookup that one request runs
        // "repeats" times from one new line is kept as a shortcut.
        'traces' => [
            'enabled' => (bool) env('BUILDER_TRACES', true),
            'report' => 'storage/logs/test-map/trace/trace.jsonl',
            'repeats' => (int) env('BUILDER_TRACE_REPEATS', 3),
        ],

        // What the change's code saved or sent where Laravel expects nothing
        // to change (direction 33): while it checks who may act, checks
        // what was sent, or builds the answer. Read from the recording
        // above, which names the phase of each thing a request did. With
        // "send_back", what the recording shows the change's own lines did
        // sends the change back for a fix, as the safety scan does, unless
        // the owner said they want it. What was only read from the code
        // goes to the reviewer. It never changes the checks' result.
        'boundaries' => [
            'enabled' => (bool) env('BUILDER_BOUNDARIES', true),
            'send_back' => (bool) env('BUILDER_BOUNDARIES_SEND_BACK', true),
            'phases' => ['authorization', 'validation', 'rendering'],
        ],

        // What the coder may ask the owner to keep of what sends a change
        // back (direction 33). The coder may argue a finding is wrong or is
        // what the owner asked for, but only the owner's yes lets it stay.
        // "asks" is the most findings it may ask about in one change, so
        // asking never replaces fixing. 0 lets it ask about none.
        'proposals' => [
            'asks' => (int) env('BUILDER_PROPOSAL_ASKS', 3),
        ],

        // How much work the app does per request in each area of its notes
        // (direction 33, a drift measure): the queries, and what it queues
        // and sends, of the area's own files, read from the recording. Each
        // area keeps a ceiling, set when a change is kept, with "slack" of
        // room. Work more than "tolerance" past it goes to the reviewer as
        // a note. In a part the owner asked to be extra careful with, work
        // more than "strict" past it sends the change back. An area seen in
        // fewer than "least" requests is not measured.
        'drift' => [
            'enabled' => (bool) env('BUILDER_DRIFT', true),
            'least' => 3,
            'slack' => 0.1,
            'tolerance' => 0.25,
            'strict' => 1.0,
        ],

        // Where the app keeps its saves and its sends (direction 33, a
        // shape rule), read from the recording: the role of the nearest of
        // the app's own code to each one, such as an Action, a Service or
        // a controller. When one role holds at least "share" of them, and
        // at least "least" places, new code that saves or sends straight
        // from a controller or a Livewire component goes to the reviewer
        // as a note. No style is assumed, and it never sends a change back
        // by itself.
        'conventions' => [
            'enabled' => (bool) env('BUILDER_CONVENTIONS', true),
            'least' => 5,
            'share' => 0.8,
        ],

        // Which areas of the notes call into which others (direction 33, a
        // drift measure), read from the chain of the app's own code that
        // the recording keeps for each thing a request did. A dependency
        // between areas that only the change's code makes goes to the
        // reviewer as a note. It never sends a change back.
        'coupling' => [
            'enabled' => (bool) env('BUILDER_COUPLING', true),
        ],

        // What the app leaves behind when one thing fails (direction 32).
        // Once the checks pass, the recorder makes one thing fail in one
        // request of one test: an email that cannot be sent, an outside
        // service that does not answer, or a save the database refuses
        // (in a transaction, or after an earlier step of a save in steps).
        // A job the sync queue ran is made to run a second time instead.
        // The places come from the recording above, in requests that ran
        // the change's code, so nothing is random. Each place runs
        // "command" once, with the name of its test added after it; the
        // test may fail, so only a missing "report" counts as not run. At
        // most "points" places are tried, and no new one starts after
        // "seconds". It never changes the checks' result. With "send_back",
        // what a caused failure shows the change's code left behind sends
        // the change back for a fix, as the safety scan does, unless the
        // owner said they want it.
        'faults' => [
            'enabled' => (bool) env('BUILDER_FAULTS', true),
            'send_back' => (bool) env('BUILDER_FAULTS_SEND_BACK', true),
            'points' => (int) env('BUILDER_FAULT_POINTS', 8),
            'seconds' => (int) env('BUILDER_FAULT_SECONDS', 180),
            'command' => ['sh', '-c', implode(' && ', [
                'test -f '.env('BUILDER_TRACE_RECORDER', '/opt/trace-recorder').'/prepend.php',
                'rm -rf storage/logs/faults',
                'mkdir -p storage/logs/faults',
                'printf \'auto_prepend_file=%s\n\' '.env('BUILDER_TRACE_RECORDER', '/opt/trace-recorder').'/prepend.php > storage/logs/faults/prepend.ini',
                'export PHP_INI_SCAN_DIR=":$PWD/storage/logs/faults" TRACE_RECORDER_DIR="$PWD/storage/logs/faults"',
                '{ php artisan test --filter="$1" > /dev/null 2>&1 || true; }',
                'test -f storage/logs/faults/trace.jsonl',
            ]), 'sh'],
            'timeout' => 120,
            'report' => 'storage/logs/faults/trace.jsonl',
        ],

        // Who may do what with the records of a change (§26.11). Once the
        // checks pass, "test" is written with one probe per route and
        // refused actor: a signed-out visitor, and another signed-in person
        // where only the person who added a record may use it. Records the
        // app already had answer to its own policy, on the routes of the
        // controllers the change touched. There, "models" is also written
        // and run to find the app's teams, and a person outside a team tries
        // the team's routes. "command" runs the test with its path after
        // it, and each probe adds a line to "report". A refused actor that
        // changed, removed, added or saw a record fails the check, so the
        // change goes back for a fix. At most "probes" are tried.
        'access' => [
            'enabled' => (bool) env('BUILDER_ACCESS_PROBES', true),
            'probes' => (int) env('BUILDER_ACCESS_PROBE_LIMIT', 40),
            'test' => 'tests/Feature/AccessProbeTest.php',
            'models' => 'storage/logs/access/models.php',
            'routes' => [
                'command' => ['sh', '-c', 'mkdir -p storage/logs/access && php artisan route:list --json > storage/logs/access/routes.json'],
                'report' => 'storage/logs/access/routes.json',
            ],
            'command' => ['sh', '-c', 'rm -f storage/logs/access/probes.jsonl && { php artisan test "$1" > storage/logs/access/test.log 2>&1 || true; }', 'sh'],
            'timeout' => 300,
            'report' => 'storage/logs/access/probes.jsonl',
        ],

        // The replay engine (direction 32): each form that adds a record the
        // change works on is sent twice as one signed-in person, in a test
        // written to "test" and taken out after. When the database refuses
        // the second send as a duplicate and the page breaks, the change
        // goes back to check the value first. The routes come from the
        // access block's route list.
        'replay' => [
            'enabled' => (bool) env('BUILDER_REPLAY_PROBES', true),
            'probes' => (int) env('BUILDER_REPLAY_PROBE_LIMIT', 10),
            'test' => 'tests/Feature/ReplayProbeTest.php',
            'command' => ['sh', '-c', 'rm -f storage/logs/access/replay.jsonl && { php artisan test "$1" > storage/logs/access/replay.log 2>&1 || true; }', 'sh'],
            'timeout' => 300,
            'report' => 'storage/logs/access/replay.jsonl',
        ],

        // The time engine (direction 32): when the change's code works with
        // dates, its own tests run with the clock stopped on an ordinary
        // day, then at moments where date code often breaks (the last
        // second of a year, the 31st of a month, a leap day). The command
        // gets the moment, the report path and the test files; the file at
        // "bootstrap" holds the extension that sets the clock. A test that
        // passes on the ordinary day and fails at a moment twice sends the
        // change back to be fixed.
        'time' => [
            'enabled' => (bool) env('BUILDER_TIME_SHIFTS', true),
            'bootstrap' => 'storage/logs/time/bootstrap.php',
            'command' => ['sh', '-c', 'moment=$1; report=$2; shift 2; rm -f "$report"; TIME_SHIFT_TO="$moment" php artisan test --bootstrap=storage/logs/time/bootstrap.php --extension=TimeShiftExtension --log-junit="$report" "$@" > /dev/null 2>&1 || true', 'sh'],
            'timeout' => 300,
            'report' => 'storage/logs/time/tests.xml',
        ],

        // Evidence about the change itself, measured by running the app
        // with and without it once the checks pass. It never changes the
        // checks' result, and it runs last: the change is taken out of the
        // workspace for it. "routes" lists the addresses the app answers
        // on both sides, as the framework names them, to show which ones
        // the change added, removed or left with other middleware. "tests"
        // runs the test files the change touched with its code taken out
        // and only its tests put back: a new test that still passes says
        // nothing about the change. The files are added after the command,
        // which writes a JUnit report to "report". The owner is told how
        // many of the change's new lines of code a test ran; when more
        // than "unrun_gap_share" of them were run by none, that is a gap.
        'change_evidence' => [
            'enabled' => (bool) env('BUILDER_CHANGE_EVIDENCE', true),
            'unrun_gap_share' => (float) env('BUILDER_UNRUN_GAP_SHARE', 0.2),
            'routes' => [
                'command' => ['sh', '-c', 'mkdir -p storage/logs && php artisan route:list --json > storage/logs/routes.json'],
                'timeout' => 60,
                'report' => 'storage/logs/routes.json',
            ],
            'tests' => [
                'command' => ['php', 'artisan', 'test', '--log-junit=storage/logs/new-tests.xml'],
                'timeout' => 300,
                'report' => 'storage/logs/new-tests.xml',
            ],
        ],

        // A check with "files" runs only on the files the change added or
        // modified with those extensions: layout and lint are about the
        // lines written, and the rest of the app is not the change's to
        // tidy. A check without it runs on the whole app, and when it fails
        // it runs again on the starting commit, so only problems the change
        // brought are sent back to be fixed. "light_repair" says when its
        // failure is one problem the coding agent's light model may fix:
        // true for any failure, or a pattern its output must match. A
        // check with a test report counts its failed tests instead. A step
        // (here or in "setup") that "needs" a file does not apply to an app
        // without it, so an app with other tools or no frontend build is
        // checked with what it has.
        'checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test', '--log-junit=storage/logs/junit.xml'], 'timeout' => 600, 'report' => 'storage/logs/junit.xml'],
            ['name' => 'Static analysis', 'command' => ['vendor/bin/phpstan', 'analyse', '--no-progress'], 'timeout' => 600, 'light_repair' => '/\bFound 1 error\b/', 'needs' => 'vendor/bin/phpstan'],
            ['name' => 'PHP formatting', 'command' => ['vendor/bin/pint', '--test'], 'timeout' => 300, 'files' => ['php'], 'light_repair' => true, 'needs' => 'vendor/bin/pint'],
            ['name' => 'Frontend format and lint', 'command' => ['npx', 'vp', 'check', '--no-error-on-unmatched-pattern'], 'timeout' => 300, 'files' => ['ts', 'vue', 'js', 'mjs', 'css', 'json', 'md'], 'needs' => 'node_modules/.bin/vp'],
            ['name' => 'TypeScript', 'command' => ['npm', 'run', 'types:check'], 'timeout' => 300, 'needs' => 'tsconfig.json'],
        ],

        // Known security problems in the packages the app uses, looked up
        // in the public advisory lists. Advice, never a check: a problem in
        // a package is rarely the change's doing, so it never fails the
        // change. The owner is told either way. An app with no packages of
        // a kind has nothing to look up there, so that lookup does not apply.
        // A lookup that cannot run (no network, no lock file) says nothing,
        // and the owner is told only about the packages that were checked. Each tool's JSON report is
        // read, not its exit code, which is also non-zero when the lookup
        // fails. Only high and critical problems count.
        'security' => [
            'enabled' => (bool) env('BUILDER_SECURITY_AUDIT', true),
            'steps' => [
                ['name' => 'PHP packages', 'report' => 'composer', 'command' => ['composer', 'audit', '--locked', '--no-interaction', '--format=json', '--abandoned=ignore', '--ignore-severity=low', '--ignore-severity=medium'], 'timeout' => 120, 'needs' => 'composer.json'],
                ['name' => 'JavaScript packages', 'report' => 'npm', 'command' => ['npm', 'audit', '--package-lock-only', '--json'], 'timeout' => 120, 'needs' => 'package.json'],
            ],
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
        // How long a link the owner shares lets others try the app, and
        // how many people can have it open at once.
        'share_days' => (int) env('BUILDER_PREVIEW_SHARE_DAYS', 7),
        'shared_sessions' => 50,
        // A preview is idle when no tab shows it, and starts again in
        // seconds, so it can stop soon after.
        'idle_minutes' => (int) env('BUILDER_PREVIEW_IDLE_MINUTES', 10),
        'max_minutes' => (int) env('BUILDER_PREVIEW_MAX_MINUTES', 240),
        // How many previews one owner can have running at once. Each holds
        // a server, so starting one more stops the owner's least used one.
        // 0 means no limit.
        'max_running_per_owner' => (int) env('BUILDER_PREVIEW_MAX_RUNNING_PER_OWNER', 3),
        // A preview seen this recently is being looked at (an open page
        // says so once a minute), so a preview started in the background
        // never stops it to make room.
        'watched_seconds' => (int) env('BUILDER_PREVIEW_WATCHED_SECONDS', 180),
        'boot_seconds' => 30,
        // Address the app's web server binds to inside the workspace. Unset,
        // it listens only where the control plane reaches it, so a runner
        // hosted apart never opens previews to its public network.
        'listen_host' => env('BUILDER_PREVIEW_LISTEN_HOST'),
        'request_timeout' => 60,

        // Ports the app's web server may listen on inside a workspace.
        'ports' => [20000, 20999],

        // Commands that prepare the project to run, after the change is applied.
        // A step that "needs" a file runs only in an app that has it.
        'setup' => [
            ['name' => 'Create .env', 'command' => ['cp', '.env.example', '.env'], 'timeout' => 30],
            ['name' => 'Install PHP dependencies', 'command' => ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'], 'timeout' => 900],
            ['name' => 'Generate app key', 'command' => ['php', 'artisan', 'key:generate', '--no-interaction'], 'timeout' => 60],
            // The database server the app asks for in .env, private to the
            // workspace (MySQL, MariaDB or PostgreSQL; SQLite needs none).
            ['name' => 'Start the database', 'command' => ['sh', '-c', (string) file_get_contents(resource_path('preview-tools/start-database.sh'))], 'timeout' => 120],
            // A large app builds hundreds of tables the first time.
            ['name' => 'Create the database', 'command' => ['php', 'artisan', 'migrate', '--force', '--no-interaction'], 'timeout' => 600],
            ['name' => 'Install Node dependencies', 'command' => $installNodeDependencies, 'timeout' => 600, 'needs' => 'package.json'],
        ],

        // Point-and-edit. An editable preview runs the locator after setup,
        // which marks each element with the template line it comes from, in
        // Vue files and Blade views alike.
        'locator' => [
            'node' => env('BUILDER_AGENT_NODE', 'node'),
            'path' => env('BUILDER_PREVIEW_LOCATOR', resource_path('preview-tools/locate-sources.mjs')),
            'directories' => ['resources/js', 'resources/views'],
        ],
        // Commands that build the frontend: after setup (and the locator),
        // and again after each visual edit.
        'build' => [
            ['name' => 'Build the frontend', 'command' => ['npm', 'run', 'build'], 'timeout' => 600, 'needs' => 'package.json'],
        ],

        // Keep the frontend build running in watch mode in an editable
        // preview, so the rebuild after an edit builds only what changed.
        // The build prints "started" when a build begins and "done" when it
        // ends. "directory" is ours in the workspace; the build must not
        // watch it. A preview whose watcher stops or takes longer than
        // "timeout" seconds runs the build steps instead. Set
        // BUILDER_PREVIEW_WATCH=false to always run the build steps.
        'watch' => [
            'enabled' => (bool) env('BUILDER_PREVIEW_WATCH', true),
            'command' => ['npm', 'run', 'build', '--', '--watch'],
            'started' => 'build started',
            'done' => 'built in',
            'path' => resource_path('preview-tools/watch-build.mjs'),
            'directory' => 'node_modules/.cache/preview-watch',
            'quiet_ms' => 150,
            'timeout' => 60,
        ],
        // Design changes are written without the app's formatting. Once an
        // editable preview shows them, the files they changed are put
        // through the app's own formatters (construction.formatters) in the
        // preview's workspace, "after_seconds" after the last change, and
        // the result is committed. An edit made on the version before such
        // a commit continues on it for "remember_days".
        'format' => [
            'enabled' => (bool) env('BUILDER_PREVIEW_FORMAT', true),
            'after_seconds' => (int) env('BUILDER_PREVIEW_FORMAT_AFTER_SECONDS', 10),
            'remember_days' => 7,
        ],
        'overlay' => resource_path('preview-tools/overlay.js'),

        // "What happened" and "What if it fails" beside the app on show
        // need the trace recorder inside the app (BUILDER_TRACE_RECORDER in
        // the box image, loaded through PHP's own prepend setting, never
        // added to the app). It records into "directory" in the workspace,
        // a path the app's own .gitignore leaves out of its repository.
        'recorder' => [
            'enabled' => (bool) env('BUILDER_PREVIEW_RECORDER', true),
            'prepend' => env('BUILDER_TRACE_RECORDER', '/opt/trace-recorder').'/prepend.php',
            'directory' => 'storage/logs/recorder',
        ],

        // The app's log inside the workspace. Email the app sends is written
        // here, and the builder shows it to the owner.
        'log' => env('BUILDER_PREVIEW_LOG', 'storage/logs/laravel.log'),

        // Environment for the app's web server. APP_URL is set to the preview's URL.
        'environment' => [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'MAIL_MAILER' => 'log',
            'MAIL_LOG_CHANNEL' => 'single',
            'QUEUE_CONNECTION' => 'sync',
        ],
    ],

    // The Evolution Benchmark (direction 21 §15): how a project's changes
    // went as the app grew. `builder:benchmark` asks for "changes" in order,
    // one at a time, as an owner would, and keeps each one whose run
    // completes; `builder:evolution` cuts the kept changes into windows
    // that end at the "checkpoints". Each change builds on the ones before
    // and adds a rule the later ones must keep. "wait" is how long one
    // change may take, in seconds.
    'benchmark' => [
        'checkpoints' => [1, 5, 10, 20, 35, 50],
        'wait' => (int) env('BUILDER_BENCHMARK_WAIT', 3600),
        'changes' => [
            'Let team members book a meeting room for a start and end time.',
            'A room cannot be booked twice for times that overlap.',
            'Only the person who made a booking can change or cancel it.',
            'Team admins can add, rename and remove rooms; other members cannot.',
            'Show each member their upcoming bookings on the dashboard.',
            'Rooms have a capacity. A booking says how many people come, and cannot be more than the room holds.',
            'Email the member when their booking is made, changed or cancelled.',
            'People from one team must never see or book another team\'s rooms.',
            'Bookings can repeat every week until a chosen date.',
            'Contractors can book only between 9:00 and 17:00 on weekdays.',
            'Suspended members keep their past bookings but cannot make new ones.',
            'Bookings longer than four hours need a team admin to approve them first.',
            'Keep a history of who made, changed or cancelled each booking, and show it to team admins.',
            'Members can belong to more than one team and switch between them.',
            'Team admins can close a room for maintenance on chosen days; nobody can book it then.',
            'Remind members by email one hour before their booking starts.',
            'Team admins can download a month of their team\'s bookings as a CSV file.',
            'When a booking is cancelled, offer the time to the first member on that room\'s waiting list.',
            'Add a read-only JSON API that lists a team\'s rooms and their free times, for other tools.',
            'Managers can invite contractors to the team, but only team admins can invite members.',
        ],
    ],

    'developer_reviews' => [
        // The change's code is shown up to this size; the rest is in the
        // code download.
        'max_patch_kb' => (int) env('BUILDER_DEVELOPER_REVIEW_MAX_PATCH_KB', 96),
        // How many kept changes in the same areas the developer sees.
        'recent_changes' => (int) env('BUILDER_DEVELOPER_REVIEW_RECENT_CHANGES', 8),
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
