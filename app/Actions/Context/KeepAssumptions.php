<?php

namespace App\Actions\Context;

use App\Actions\Features\ListDecisions;
use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Models\Workspace;
use App\Runs\Plan;
use App\Workspaces\WorkspaceManager;

class KeepAssumptions
{
    /**
     * The notes section a change's assumptions live in.
     */
    public const SECTION = 'Assumptions';

    /**
     * How an assumption is known. The owner's answers are decisions, kept
     * apart (RecordDecision), so all that is left here was assumed.
     */
    public const ASSUMED = '(assumed)';

    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Write the plan's assumptions about how the app behaves into the
     * workspace's notes (§30.3), so the next change reads what
     * this one took for granted. They go to the area the change was about,
     * or to the project notes when it was about none or several.
     *
     * The notes are read back from the workspace afterwards, so keeping the
     * change keeps them and undoing it takes them out, like any other notes
     * edit. Choices about how the code is built stay out: the owner reads
     * these notes. A replayed known solution assumed nothing.
     *
     * @param  list<string>  $targets  The areas the change was about, by key
     * @return list<string> The assumptions written
     */
    public function handle(Workspace $workspace, Plan $plan, array $targets): array
    {
        $assumptions = array_values(array_filter(
            array_map(trim(...), $plan->assumptions),
            fn (string $assumption) => $assumption !== '' && preg_match(ListDecisions::BUILD_WORDS, $assumption) !== 1,
        ));

        if ($plan->solutionKey !== null || $assumptions === []) {
            return [];
        }

        $driver = $this->workspaces->driver($workspace->driver);
        $directory = ProjectNotes::directory();
        $file = count($targets) === 1 ? ProjectContext::CAPABILITIES_DIRECTORY."/{$targets[0]}.md" : ProjectContext::PROJECT_FILE;
        $contents = rescue(fn () => $driver->readFile((string) $workspace->driver_id, "{$directory}/{$file}"), null, report: false);

        // An area with no notes of its own shares the project's.
        if (! is_string($contents) && $file !== ProjectContext::PROJECT_FILE) {
            $file = ProjectContext::PROJECT_FILE;
            $contents = rescue(fn () => $driver->readFile((string) $workspace->driver_id, "{$directory}/{$file}"), null, report: false);
        }

        // An app with no notes yet gets none from here: its first notes are
        // the owner's to start.
        if (! is_string($contents)) {
            return [];
        }

        $notes = NotesDocument::parse($contents);

        $kept = array_map(fn (string $item) => trim(str_replace(self::ASSUMED, '', $item)), $notes->items(self::SECTION));
        $new = array_values(array_unique(array_diff($assumptions, $kept)));

        if ($new === []) {
            return [];
        }

        $section = trim((string) $notes->section(self::SECTION));
        $bullets = implode("\n", array_map(fn (string $assumption) => "- {$assumption} ".self::ASSUMED, $new));
        $driver->writeFile((string) $workspace->driver_id, "{$directory}/{$file}", $notes->withSection(self::SECTION, trim("{$section}\n{$bullets}"))->toMarkdown());

        return $new;
    }
}
