<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evaluation
    |--------------------------------------------------------------------------
    |
    | Settings for comparing the pipeline with a plain coding agent on a test
    | project (see the suite's README.md). Only for development: with a
    | hand-off directory set, every model call is written to that directory
    | and waits for an outside responder's answer, and the app refuses to
    | start in production.
    |
    */

    'handoff' => [
        'path' => env('BUILDER_EVAL_HANDOFF'),
        'timeout_seconds' => (int) env('BUILDER_EVAL_HANDOFF_TIMEOUT', 3600),
    ],

    // Directory holding the suite's manifest.json, hidden/ and sabotage/.
    // Relative paths are resolved from the application's base path.
    'suite' => env('BUILDER_EVAL_SUITE'),

    // The project the tasks change.
    'project' => env('BUILDER_EVAL_PROJECT'),

    // A copy of the project with its dependencies installed (vendor/ and
    // node_modules/), copied into each workspace instead of installing.
    'dependencies' => env('BUILDER_EVAL_DEPENDENCIES'),

    // Where results, reports and owner bundles are written.
    'results' => env('BUILDER_EVAL_RESULTS', storage_path('app/evaluation')),

    // Where the plain agent's and the scorer's workspaces are made.
    'workspaces' => env('BUILDER_EVAL_WORKSPACES', sys_get_temp_dir().DIRECTORY_SEPARATOR.'builder-evaluation'),

    'audit' => [
        // Paths no agent may touch besides the hidden material, the hand-off
        // directory and the results, comma separated: for example the
        // orchestrator's own scratch directory.
        'forbidden' => array_values(array_filter(explode(',', (string) env('BUILDER_EVAL_AUDIT_FORBIDDEN', '')))),
    ],

];
