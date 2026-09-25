<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait UsesReferenceSolutions
{
    /**
     * Write a small reference-solutions manifest with two patches, and a
     * project source they apply to in "{directory}/source", and point the
     * reference generator at it.
     */
    protected function useReferenceSolutions(): string
    {
        $directory = storage_path('framework/testing/reference-'.Str::lower(Str::random(8)));

        File::ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        $step = fn (string $detail) => [
            'key' => 'permission',
            'kind' => 'permission',
            'label' => 'Who may invite people',
            'file' => 'app/Policies/TeamPolicy.php',
            'symbol' => 'TeamPolicy::inviteMember',
            'detail' => $detail,
        ];

        File::put("{$directory}/manifest.json", json_encode(['solutions' => [
            [
                'key' => 'team-invitations',
                'patch' => '01.patch',
                'match' => ['invite'],
                'summary' => 'Owners and admins can invite people.',
                'steps' => [$step('Owners and admins.')],
                'acceptance' => ['Invitations/ContractTest.php'],
            ],
            [
                'key' => 'owner-only-invitations',
                'patch' => '02.patch',
                'follows' => 'team-invitations',
                'step' => 'permission',
                'match' => ['owner'],
                'summary' => 'Only the owner can invite people.',
                'steps' => [$step('Owner only.')],
            ],
        ]], JSON_THROW_ON_ERROR));

        File::put("{$directory}/01.patch", implode("\n", [
            'diff --git a/app/Policies/TeamPolicy.php b/app/Policies/TeamPolicy.php',
            '--- a/app/Policies/TeamPolicy.php',
            '+++ b/app/Policies/TeamPolicy.php',
            '@@ -1,3 +1,5 @@',
            ' <?php',
            '+// invite',
            '+// members:invite',
            ' ',
            ' class TeamPolicy {}',
            'diff --git a/config/teams.php b/config/teams.php',
            '--- a/config/teams.php',
            '+++ b/config/teams.php',
            '@@ -3,5 +3,6 @@',
            ' return [',
            "     'admin' => [",
            "         'members:view',",
            "+        'members:invite',",
            '     ],',
            ' ];',
            '',
        ]));

        File::put("{$directory}/02.patch", implode("\n", [
            'diff --git a/config/teams.php b/config/teams.php',
            '--- a/config/teams.php',
            '+++ b/config/teams.php',
            '@@ -3,6 +3,5 @@',
            ' return [',
            "     'admin' => [",
            "         'members:view',",
            "-        'members:invite',",
            '     ],',
            ' ];',
            '',
        ]));

        File::ensureDirectoryExists("{$directory}/source/app/Policies");
        File::ensureDirectoryExists("{$directory}/source/config");
        File::put("{$directory}/source/app/Policies/TeamPolicy.php", "<?php\n\nclass TeamPolicy {}\n");
        File::put("{$directory}/source/config/teams.php", "<?php\n\nreturn [\n    'admin' => [\n        'members:view',\n    ],\n];\n");

        config([
            'builder.generator' => 'reference',
            'builder.generators.reference.path' => $directory,
        ]);

        return $directory;
    }
}
