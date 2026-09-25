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

    'generators' => [

        'reference' => [
            // Directory holding manifest.json and the patches it names.
            // Relative paths are resolved from the application's base path.
            'path' => env('BUILDER_REFERENCE_SOLUTIONS'),
        ],

    ],

];
