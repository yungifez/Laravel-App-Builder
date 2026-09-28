<?php

namespace App\Workspaces\Drivers;

class CopyExclusions
{
    /**
     * Paths never copied into a workspace: installed dependencies are rebuilt
     * from lockfiles, secrets must not leave the source machine, and notes
     * older versions kept in the app live in our database now.
     *
     * @var list<string>
     */
    public const PATHS = ['./.git', './vendor', './node_modules', './.env', './public/build', './public/hot', './.builder'];

    /**
     * Build the tar --exclude flags for the excluded paths.
     */
    public static function tarFlags(): string
    {
        return implode(' ', array_map(fn (string $path) => "--exclude='{$path}'", self::PATHS));
    }

    /**
     * Build the git apply flags that skip the excluded paths. A workspace
     * never has them, so a change that touched them (older changes edited
     * the app's notes in .builder) would otherwise not apply at all.
     *
     * @return list<string>
     */
    public static function applyFlags(): array
    {
        return array_merge(...array_map(function (string $path) {
            $path = substr($path, 2);

            return ["--exclude={$path}", "--exclude={$path}/*"];
        }, self::PATHS));
    }
}
