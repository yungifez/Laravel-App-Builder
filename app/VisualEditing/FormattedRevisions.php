<?php

namespace App\VisualEditing;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * The commits that only formatted the app's code after design changes.
 * Formatting moves the app on while the owner may still be editing the
 * version before it; an edit made on that version continues on the
 * formatted one, as nothing the owner sees changed.
 */
class FormattedRevisions
{
    /** The most formatting commits to follow in a row. */
    protected const MAX_STEPS = 10;

    /**
     * Remember that a commit only formatted the version before it.
     */
    public function record(Project $project, string $parent, string $sha): void
    {
        Cache::put(self::key($project, $parent), $sha, now()->addDays((int) config('builder.preview.format.remember_days')));
    }

    /**
     * Get the commit that only formatted a version, if there is one.
     */
    public function after(Project $project, string $revision): ?string
    {
        $sha = Cache::get(self::key($project, $revision));

        return is_string($sha) ? $sha : null;
    }

    /**
     * Get the version to build on: the given one, or the newest commit
     * that only formatted it.
     */
    public function latest(Project $project, string $revision): string
    {
        for ($step = 0; $step < self::MAX_STEPS && ($next = $this->after($project, $revision)) !== null; $step++) {
            $revision = $next;
        }

        return $revision;
    }

    protected static function key(Project $project, string $revision): string
    {
        return "projects:{$project->id}:formatted:{$revision}";
    }
}
