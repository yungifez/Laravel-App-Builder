<?php

namespace App\Ai\Tools;

use App\Runs\ToolSession;

class WorkspaceTools
{
    /**
     * Model-facing adapters for the workspace tools, by tool name.
     *
     * @var array<string, class-string<WorkspaceTool>>
     */
    protected const ADAPTERS = [
        'read_file' => ReadFileTool::class,
        'list_files' => ListFilesTool::class,
        'search' => SearchTool::class,
        'write_file' => WriteFileTool::class,
        'apply_patch' => ApplyPatchTool::class,
        'run_command' => RunCommandTool::class,
    ];

    /**
     * Get adapters for the tools the session allows, journaled under the given prefix.
     *
     * @return list<WorkspaceTool>
     */
    public static function for(ToolSession $session, string $operationPrefix): array
    {
        $tools = [];

        foreach ($session->tools() as $name) {
            if (isset(self::ADAPTERS[$name])) {
                $tools[] = new (self::ADAPTERS[$name])($session, $operationPrefix);
            }
        }

        return $tools;
    }
}
