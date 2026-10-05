<?php

namespace App\Actions\Context;

use App\Actions\Runs\ExtractCandidateChange;
use App\Actions\Runs\RecordModelUsage;
use App\Ai\Agents\NotesKeeper;
use App\Context\Capability;
use App\Context\ContextPack;
use App\Context\ProjectNotes;
use App\Enums\ModelRole;
use App\Models\Run;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Bring the notes up to date after a worker's change (architecture §11).
 * The worker's copy of the app has no notes, so its change never touches
 * them; the reviewer's model rewrites the notes of the areas the change
 * touched, from the change and the worker's summary. The new notes go into
 * the workspace, where they are read back like our own agent's.
 */
class KeepWorkerNotes
{
    /**
     * How much of the change the model reads. A worker's patch can be far
     * larger than what the notes need.
     */
    protected const MAX_CHANGE_BYTES = 60000;

    public function __construct(
        private ExtractCandidateChange $extractCandidateChange,
        private WorkspaceManager $workspaces,
        private RecordModelUsage $recordModelUsage,
    ) {}

    /**
     * Update the notes of the areas the applied change touched. A failed
     * update leaves the notes as they were and never fails the change.
     */
    public function handle(Run $run, Workspace $workspace, string $summary): void
    {
        if (! config('builder.agents.workers.keep_notes') || $run->context === null) {
            return;
        }

        $patch = $this->extractCandidateChange->handle($workspace);
        $notes = $this->notes($workspace, $this->touched(ContextPack::fromArray($run->context)->projectContext()->capabilities, $patch));

        if ($notes === []) {
            return;
        }

        try {
            $response = NotesKeeper::make()->prompt($this->prompt($summary, $patch, $notes), provider: ModelRole::Reviewer->providers());
            $this->recordModelUsage->handle($run, ModelRole::Reviewer, $response);

            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('The notes came back without a shape.');
            }
        } catch (Throwable $exception) {
            report($exception);
            $run->recordEvent('notes_not_updated', ['reason' => Str::limit($exception->getMessage(), 500)]);

            return;
        }

        $driver = $this->workspaces->driver($workspace->driver);

        foreach ($response['files'] ?? [] as $file) {
            // Only the notes it was shown, so it cannot write anywhere else.
            if (is_array($file) && is_string($file['path'] ?? null) && is_string($file['contents'] ?? null) && array_key_exists($file['path'], $notes)) {
                $driver->writeFile((string) $workspace->driver_id, ProjectNotes::directory().'/'.$file['path'], $file['contents']);
            }
        }
    }

    /**
     * Get the notes files of the areas that claim a file the change touched.
     *
     * @param  array<string, Capability>  $capabilities
     * @return list<string>
     */
    protected function touched(array $capabilities, string $patch): array
    {
        preg_match_all('#^diff --git a/(\S+) b/(\S+)$#m', $patch, $matches);
        $paths = array_unique([...$matches[1], ...$matches[2]]);
        $files = [];

        foreach ($capabilities as $capability) {
            foreach ($paths as $path) {
                if ($capability->file !== null && $capability->claims($path)) {
                    $files[] = $capability->file;

                    break;
                }
            }
        }

        return $files;
    }

    /**
     * Read the given notes files from the workspace, skipping any it lacks.
     *
     * @param  list<string>  $files
     * @return array<string, string>
     */
    protected function notes(Workspace $workspace, array $files): array
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $notes = [];

        foreach ($files as $file) {
            ProjectNotes::assertPath($file);
            $contents = rescue(fn () => $driver->readFile((string) $workspace->driver_id, ProjectNotes::directory().'/'.$file), null, report: false);

            if (is_string($contents)) {
                $notes[$file] = $contents;
            }
        }

        return $notes;
    }

    /**
     * @param  array<string, string>  $notes
     */
    protected function prompt(string $summary, string $patch, array $notes): string
    {
        $files = implode("\n\n", array_map(fn (string $path, string $contents) => "--- {$path}\n{$contents}", array_keys($notes), $notes));

        return "The author's summary:\n{$summary}\n\nThe change:\n".Str::limit($patch, self::MAX_CHANGE_BYTES, "\n(the rest of the change is left out)")."\n\nThe notes of the areas it touched:\n\n{$files}";
    }
}
