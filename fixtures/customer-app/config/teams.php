<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Team Role Permissions
    |--------------------------------------------------------------------------
    |
    | Each team role maps to the permissions its members hold on their team.
    | The "*" wildcard grants every permission. Policies check these values,
    | so changing them here changes who may perform each team action.
    |
    | Available permissions: "team:update", "members:update-role",
    | "members:remove".
    |
    */

    'roles' => [
        'owner' => [
            'permissions' => ['*'],
        ],

        'admin' => [
            'permissions' => [
                'team:update',
                'members:update-role',
                'members:remove',
            ],
        ],

        'member' => [
            'permissions' => [],
        ],
    ],

];
