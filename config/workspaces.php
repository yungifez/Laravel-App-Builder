<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Workspace Driver
    |--------------------------------------------------------------------------
    |
    | Workspaces are disposable environments that run customer code. The
    | "docker" driver runs them as capped local containers. It is meant for
    | development and CI against trusted fixtures only, because containers share
    | the host kernel. Untrusted customer code needs a microVM-backed driver
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

        'docker' => [
            'binary' => env('WORKSPACE_DOCKER_BINARY', 'docker'),
            'image' => env('WORKSPACE_DOCKER_IMAGE', 'php:8.4-cli'),
            'network' => env('WORKSPACE_DOCKER_NETWORK', 'none'),
            'workdir' => '/workspace',
        ],

    ],

];
