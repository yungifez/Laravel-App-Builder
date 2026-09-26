<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Workspace Driver
    |--------------------------------------------------------------------------
    |
    | Workspaces are disposable environments that run customer code. The
    | "docker" driver runs them as capped local containers and the "local"
    | driver as plain directories on this host. Both are for development and
    | CI against trusted fixtures only: containers share the host kernel, and
    | local workspaces have no isolation beyond a scrubbed environment. Untrusted customer code needs a microVM-backed driver
    | (see docs/research/workspace-sandboxes.md).
    |
    */

    'default' => env('WORKSPACE_DRIVER', 'docker'),

    /*
    |--------------------------------------------------------------------------
    | Workspace Size
    |--------------------------------------------------------------------------
    |
    | Hard ceilings applied to every workspace: CPU cores (fractions allowed),
    | memory in megabytes (swap is disabled) and the maximum number of processes.
    |
    */

    'size' => [
        'cpus' => (float) env('WORKSPACE_CPUS', 2),
        'memory_mb' => (int) env('WORKSPACE_MEMORY_MB', 2048),
        'pids' => (int) env('WORKSPACE_PIDS', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    |
    | Every command gets a timeout in seconds; the process is killed when it
    | runs out. Each owner may run a limited number of commands at once across
    | all their workspaces; extra commands wait up to "wait_seconds" for a free
    | slot and are then rejected. Output beyond "output_limit" bytes per stream
    | is truncated before it is stored.
    |
    */

    'commands' => [
        'timeout' => (int) env('WORKSPACE_COMMAND_TIMEOUT', 300),
        'per_owner' => (int) env('WORKSPACE_COMMANDS_PER_OWNER', 2),
        'wait_seconds' => (int) env('WORKSPACE_COMMAND_WAIT', 30),
        'output_limit' => (int) env('WORKSPACE_OUTPUT_LIMIT', 65536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifetime
    |--------------------------------------------------------------------------
    |
    | Workspaces idle for "idle_minutes" or older than "max_minutes" are
    | destroyed by the scheduled workspaces:reap command.
    |
    */

    'lifetime' => [
        'idle_minutes' => (int) env('WORKSPACE_IDLE_MINUTES', 30),
        'max_minutes' => (int) env('WORKSPACE_MAX_MINUTES', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        'local' => [
            // Keep workspaces outside this repository, so tools running in
            // them never pick up the control plane's git or ignore files.
            'root' => env('WORKSPACE_LOCAL_ROOT', sys_get_temp_dir().DIRECTORY_SEPARATOR.'builder-workspaces'),
            'image' => 'host',
            // A coding agent here runs beside the control plane and can read
            // its files, including .env. Allow it only for apps you trust,
            // such as our own fixtures, and never in production.
            'agents' => (bool) env('WORKSPACE_LOCAL_AGENTS', false),
            // Only these variables reach commands; everything else is scrubbed.
            'env_passthrough' => [
                'PATH', 'HOME', 'LANG', 'COMPOSER_HOME', 'COMPOSER_ALLOW_SUPERUSER',
                'HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY', 'https_proxy', 'http_proxy', 'no_proxy',
                'SSL_CERT_FILE', 'NODE_EXTRA_CA_CERTS', 'REQUESTS_CA_BUNDLE',
            ],
        ],

        'docker' => [
            'binary' => env('WORKSPACE_DOCKER_BINARY', 'docker'),
            'image' => env('WORKSPACE_DOCKER_IMAGE', 'php:8.4-cli'),
            'network' => env('WORKSPACE_DOCKER_NETWORK', 'none'),
            'workdir' => '/workspace',
        ],

        // Each workspace lives in a box with a runner in it
        // (resources/box-runner). The runner connects out to the control
        // plane: it fetches commands and posts results over HTTPS, and
        // "socket_url" (Reverb) only wakes it when work arrives. Boxes come
        // from the provider in "boxes".
        'runner' => [
            'image' => 'box',
            'provider' => env('WORKSPACE_BOX_PROVIDER', 'static'),
            'socket_url' => env('WORKSPACE_RUNNER_SOCKET_URL'),
            'poll_seconds' => (int) env('WORKSPACE_RUNNER_POLL_SECONDS', 5),
            // Where the coding agent runner is inside a box.
            'agent_runner' => env('WORKSPACE_RUNNER_AGENT_RUNNER', '/opt/agent-runner/run.mjs'),
            // Where the preview locator is inside a box. It stays outside the
            // workspace, so it never reaches the customer's repository.
            'preview_locator' => env('WORKSPACE_RUNNER_PREVIEW_LOCATOR', '/opt/preview-tools/locate-sources.mjs'),
            // A runner must take a command within "answer_seconds" and
            // finish it within its timeout plus "grace_seconds".
            'answer_seconds' => (int) env('WORKSPACE_RUNNER_ANSWER_SECONDS', 60),
            'grace_seconds' => 30,
            'file_seconds' => 60,
            'poll_ms' => 200,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Box Providers
    |--------------------------------------------------------------------------
    |
    | Where the runner driver gets its boxes. "static" is one runner that is
    | already running, such as the runner container in local development; it
    | holds every workspace, so it is for trusted apps only. "docker" makes a
    | container per workspace on this machine, to try the lifecycle of real
    | boxes locally. A provider for a hosting service is added as another
    | entry here and in App\Workspaces\Boxes\BoxProviderManager.
    |
    */

    'boxes' => [
        'static' => [
            'runner' => env('WORKSPACE_RUNNER_NAME', 'local'),
            'token' => env('WORKSPACE_RUNNER_TOKEN', ''),
            'service_host' => env('WORKSPACE_RUNNER_SERVICE_HOST', 'runner'),
        ],

        // One container per workspace, through the box service in
        // docker/boxes, which holds the Docker socket. Local development
        // only: containers share the host's kernel.
        'docker' => [
            'url' => env('WORKSPACE_BOXES_URL', 'http://boxes:8090'),
            'token' => env('WORKSPACE_BOXES_TOKEN', ''),
            // Where a box's runner reaches the control plane.
            'control_plane_url' => env('WORKSPACE_RUNNER_CONTROL_PLANE_URL', 'http://laravel.test'),
        ],
    ],

];
