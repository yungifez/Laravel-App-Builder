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
}
