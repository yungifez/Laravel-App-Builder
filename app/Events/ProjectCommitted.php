<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new commit reached the project's branch: a kept change, an undo, a
 * visual edit or a notes edit.
 */
class ProjectCommitted
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Project $project, public string $sha) {}
}
