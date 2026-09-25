<?php

namespace App\Workspaces\Drivers;

class CopyExclusions
{
    /**
     * Paths never copied into a workspace: installed dependencies are rebuilt
     * from lockfiles, and secrets must not leave the source machine.
     *
     * @var list<string>
     */
    public const PATHS = ['./.git', './vendor', './node_modules', './.env', './public/build', './public/hot'];

    /**
     * Build the tar --exclude flags for the excluded paths.
     */
    public static function tarFlags(): string
    {
        return implode(' ', array_map(fn (string $path) => "--exclude='{$path}'", self::PATHS));
    }
}
