<?php

namespace Tests\Fakes;

use App\Runs\Contracts\MutatingTool;
use App\Runs\ToolContext;
use App\Workspaces\Exceptions\WorkspaceBusyException;

/**
 * A changing tool whose workspace command never gets a slot.
 */
class BusyWorkspaceTool implements MutatingTool
{
    public function rules(): array
    {
        return [];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        throw WorkspaceBusyException::forOwner($context->run->featureRequest->user_id);
    }

    public function reconcile(ToolContext $context, array $arguments): ?array
    {
        return null;
    }
}
