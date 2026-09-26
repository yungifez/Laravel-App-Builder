<?php

namespace App\Jobs;

use App\Actions\Runs\RecordModelUsage;
use App\Ai\Agents\NotesDrafter;
use App\Enums\ModelRole;
use App\Enums\NotesDraftStatus;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class DraftProjectNotes implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: one model call.
     */
    public int $timeout = 600;

    /**
     * A failed draft is not retried; the owner can write the notes instead.
     */
    public int $tries = 1;

    /**
     * The files whose contents the drafter reads, when they exist.
     *
     * @var list<string>
     */
    protected const KEY_FILES = ['README.md', 'composer.json', 'routes/web.php', 'routes/settings.php', 'routes/api.php'];

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    /**
     * Read the app's outline and key files, ask the planner-tier model for
     * a draft, and keep only what fits the notes format.
     */
    public function handle(ProjectRepository $repository): void
    {
        if ($this->project->fresh()?->notes_draft_status !== NotesDraftStatus::Drafting) {
            return;
        }

        try {
            $head = $repository->head($this->project);
            $files = array_values(array_filter($repository->files($this->project, $head), fn (string $path) => ! Str::startsWith($path, ['public/', 'storage/', 'database/migrations/', 'bootstrap/', '.'])));

            $prompt = "Files:\n".implode("\n", array_slice($files, 0, 1500));

            foreach (self::KEY_FILES as $path) {
                $contents = $repository->show($this->project, $head, $path);

                if ($contents !== null) {
                    $prompt .= "\n\n--- {$path}\n".mb_substr($contents, 0, 6000);
                }
            }

            $response = NotesDrafter::make()->prompt($prompt, provider: ModelRole::Planner->provider(), model: ModelRole::Planner->model());

            $this->project->update(['setup_model_calls' => [...$this->project->setup_model_calls ?? [], [
                'role' => ModelRole::Planner->value,
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'cost_usd' => RecordModelUsage::cost((string) $response->meta->model, $response->usage->inputTokens, $response->usage->outputTokens),
            ]]]);

            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('The notes drafter did not return structured output.');
            }

            $this->project->update([
                'notes_draft_status' => NotesDraftStatus::Ready,
                'notes_draft' => self::normalize($response->structured, $files),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->failed($exception);
        }
    }

    /**
     * Record that no draft could be made.
     */
    public function failed(?Throwable $exception): void
    {
        $this->project->update([
            'notes_draft_status' => NotesDraftStatus::Failed,
            'notes_draft_error' => __('I could not read your app well enough to describe it. You can write the notes yourself on this page.'),
        ]);
    }

    /**
     * Keep only well-formed areas, with unique keys and paths that match
     * files in the app.
     *
     * @param  array<mixed>  $draft
     * @param  list<string>  $files
     * @return array{purpose: string, areas: list<array{key: string, name: string, summary: string, paths: list<string>, behaviors: list<array{key: string, name: string}>, rules: list<string>}>}
     */
    public static function normalize(array $draft, array $files): array
    {
        $areas = [];

        foreach (is_array($draft['areas'] ?? null) ? $draft['areas'] : [] as $area) {
            if (! is_array($area)) {
                continue;
            }

            $key = Str::slug((string) ($area['key'] ?? $area['name'] ?? ''));

            if ($key === '' || isset($areas[$key])) {
                continue;
            }

            $paths = array_values(array_filter(
                array_map(strval(...), is_array($area['paths'] ?? null) ? $area['paths'] : []),
                fn (string $pattern) => array_filter($files, fn (string $file) => Str::is($pattern, $file)) !== [],
            ));

            $behaviors = [];

            foreach (is_array($area['behaviors'] ?? null) ? $area['behaviors'] : [] as $behavior) {
                $behaviorKey = is_array($behavior) ? Str::slug((string) ($behavior['key'] ?? $behavior['name'] ?? '')) : '';

                if (is_array($behavior) && $behaviorKey !== '' && ! isset($behaviors[$behaviorKey])) {
                    $behaviors[$behaviorKey] = ['key' => $behaviorKey, 'name' => Str::limit(trim((string) ($behavior['name'] ?? '')), 200, '')];
                }
            }

            $areas[$key] = [
                'key' => Str::limit($key, 60, ''),
                'name' => Str::limit(trim((string) ($area['name'] ?? Str::headline($key))), 100, ''),
                'summary' => Str::limit(trim((string) ($area['summary'] ?? '')), 500, ''),
                'paths' => array_slice($paths, 0, 50),
                'behaviors' => array_slice(array_values($behaviors), 0, 50),
                'rules' => array_values(array_filter(array_map(fn ($rule) => trim((string) $rule), is_array($area['rules'] ?? null) ? $area['rules'] : []))),
            ];
        }

        return ['purpose' => trim((string) ($draft['purpose'] ?? '')), 'areas' => array_values($areas)];
    }
}
