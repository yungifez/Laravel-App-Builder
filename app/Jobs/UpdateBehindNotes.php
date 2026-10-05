<?php

namespace App\Jobs;

use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\UpdateProjectNotes;
use App\Actions\Runs\RecordModelUsage;
use App\Ai\Agents\NotesKeeper;
use App\Context\ProjectNotes;
use App\Enums\ModelRole;
use App\Models\Run;
use App\Runs\Exceptions\ProvidersUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class UpdateBehindNotes implements ShouldQueue
{
    use Queueable;

    /**
     * How much of the change the model reads, as for a worker's change.
     */
    protected const MAX_CHANGE_BYTES = 60000;

    /**
     * One model call, which may take up to its own timeout.
     */
    public int $timeout = 600;

    /**
     * A failed update is not retried: the owner sees why and can ask again.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Run $run) {}

    /**
     * Bring the notes of the parts a kept change left behind up to date, at
     * the owner's request. The reviewer's model reads the change and the
     * notes and rewrites what the change made wrong (NotesKeeper, as after a
     * worker's change). Each file is saved through UpdateProjectNotes, so a
     * file the owner edited meanwhile, or one that would change what a part
     * is or is connected to, is never saved. A failure leaves every note as
     * it was and says why.
     */
    public function handle(ProjectNotes $projectNotes, ReadProjectContext $readProjectContext, UpdateProjectNotes $updateProjectNotes, RecordModelUsage $recordModelUsage): void
    {
        $request = $this->run->featureRequest;
        $project = $request->project;
        $branch = (string) $request->branch();
        $areas = $this->run->review['classification']['notes_behind'];

        $version = $projectNotes->version($project, $branch);
        $saved = $projectNotes->files($project, $branch);
        $capabilities = $readProjectContext->current($project, $branch)->capabilities;
        $notes = [];

        foreach ($areas as $area) {
            $file = $capabilities[$area]->file ?? null;

            if ($file !== null && isset($saved[$file])) {
                $notes[$file] = ['area' => $area, 'contents' => $saved[$file]];
            }
        }

        if ($notes === []) {
            $this->stop(new RuntimeException(__('These parts have no notes yet, so there is nothing to update. You can write them in Your business.')), ours: false);

            return;
        }

        try {
            $response = NotesKeeper::make()->prompt($this->prompt($notes), provider: ModelRole::Reviewer->providers());
            $recordModelUsage->handle($this->run, ModelRole::Reviewer, $response);

            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('The notes came back without a shape.');
            }
        } catch (FailoverableException $exception) {
            $this->stop(ProvidersUnavailable::because($exception));

            return;
        } catch (RequestException $exception) {
            $stop = ProvidersUnavailable::fromResponse($exception);
            $this->run->recordEvent('ai_service_error', ['reason' => $stop->reason()->value, ...(array) $stop->serviceError()]);
            $this->stop($stop);

            return;
        }

        $updated = [];

        foreach ($response['files'] ?? [] as $file) {
            // Only the notes it was shown, so it cannot write anywhere else.
            if (! is_array($file) || ! is_string($file['path'] ?? null) || ! is_string($file['contents'] ?? null) || ! isset($notes[$file['path']])) {
                continue;
            }

            try {
                $version = $updateProjectNotes->handle($project, 'notes:'.$notes[$file['path']]['area'], $file['contents'], $version, $branch);
                $updated[] = $notes[$file['path']]['area'];
            } catch (ValidationException $exception) {
                // The owner's own edit since wins; anything else the model
                // got wrong leaves that file as it was.
                if ($projectNotes->version($project, $branch) !== $version) {
                    $this->stop(new RuntimeException(__('Your notes changed while I was updating them, so I left them as they were. Ask again to update them.')), ours: false);

                    return;
                }

                if (($exception->errors()['body'][0] ?? null) !== __('Nothing changed.')) {
                    report($exception);
                }
            }
        }

        sort($updated);
        $this->run->recordEvent('notes_updated', ['areas' => $updated]);
    }

    /**
     * Say why the notes were not updated, when the job itself failed, such
     * as running out of time, so the owner is never left waiting.
     */
    public function failed(?Throwable $exception): void
    {
        $this->stop($exception ?? new RuntimeException('The update stopped.'));
    }

    /**
     * Record why the notes stayed as they were. Our own failures say so.
     */
    protected function stop(Throwable $exception, bool $ours = true): void
    {
        if (! $exception instanceof ProvidersUnavailable && $ours) {
            report($exception);
        }

        $this->run->recordEvent('notes_update_failed', [
            'reason' => $exception instanceof ProvidersUnavailable ? $exception->reason()->value : ($ours ? 'ours' : 'owner'),
            'message' => match (true) {
                $exception instanceof ProvidersUnavailable, ! $ours => $exception->getMessage(),
                default => __('This is our fault: I could not update these notes. They are as they were. Try again later.'),
            },
        ]);
    }

    /**
     * @param  array<string, array{area: string, contents: string}>  $notes
     */
    protected function prompt(array $notes): string
    {
        $request = $this->run->featureRequest;
        $files = implode("\n\n", array_map(fn (string $path, array $note) => "--- {$path}\n{$note['contents']}", array_keys($notes), $notes));

        return "The author's summary:\n{$this->run->review['summary']}\n\nThe change:\n".Str::limit((string) $request->patch, self::MAX_CHANGE_BYTES, "\n(the rest of the change is left out)")."\n\nThe notes of the areas it touched:\n\n{$files}";
    }
}
