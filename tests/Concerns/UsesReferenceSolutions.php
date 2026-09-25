<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait UsesReferenceSolutions
{
    /**
     * Write a small reference-solutions manifest with two patches and point
     * the reference generator at it.
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
            '@@ -1,2 +1,3 @@',
            ' <?php',
            '+// invite',
            '+// members:invite',
            'diff --git a/config/teams.php b/config/teams.php',
            '--- a/config/teams.php',
            '+++ b/config/teams.php',
            '@@ -1,2 +1,2 @@',
            '-old',
            '+new',
            '',
        ]));

        File::put("{$directory}/02.patch", implode("\n", [
            'diff --git a/config/teams.php b/config/teams.php',
            '--- a/config/teams.php',
            '+++ b/config/teams.php',
            '@@ -1,2 +1,1 @@',
            "-                'members:invite',",
            '',
        ]));

        config([
            'builder.generator' => 'reference',
            'builder.generators.reference.path' => $directory,
        ]);

        return $directory;
    }
}
