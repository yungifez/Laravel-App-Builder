<?php

namespace App\Jobs;

use App\Actions\Context\GatherAppFacts;
use App\Actions\Runs\RecordModelUsage;
use App\Ai\Agents\NotesDrafter;
use App\Enums\ModelRole;
use App\Enums\NotesDraftStatus;
use App\Features\TestMap;
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
     * The number of seconds the job can run: setting the app up, running
     * its tests, and one model call.
     */
    public int $timeout = 3600;

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
     * Explore the app: gather what its code holds and what its tests touch,
     * ask the planner-tier model to word that for the owner, and keep only
     * what fits the notes format and what the facts back.
     */
    public function handle(ProjectRepository $repository, GatherAppFacts $gatherAppFacts): void
    {
        if ($this->project->fresh()?->notes_draft_status !== NotesDraftStatus::Drafting) {
            return;
        }

        try {
            $head = $repository->head($this->project);
            $files = self::appFiles($repository, $this->project, $head);

            $facts = $gatherAppFacts->handle($this->project, $head, $files);
            $prompt = self::outline($repository, $this->project, $head, $files)."\n\n".$facts['text'];

            $response = NotesDrafter::make()->prompt($prompt, provider: ModelRole::Planner->providers());

            $cost = RecordModelUsage::cost((string) $response->meta->model, $response->usage->inputTokens, $response->usage->outputTokens);

            $this->project->update(['setup_model_calls' => [...$this->project->setup_model_calls ?? [], [
                'role' => ModelRole::Planner->value,
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'cost_usd' => $cost,
                'cost_source' => $cost === null ? null : 'estimated',
                'at' => now()->toIso8601String(),
            ]]]);

            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('The notes drafter did not return structured output.');
            }

            $this->project->update([
                'notes_draft_status' => NotesDraftStatus::Ready,
                'notes_draft' => self::withEvidence(self::normalize($response->structured, $files), $files, $facts['map'], $facts['pages']),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->failed($exception);
        }
    }

    /**
     * Get the app's outline: its files and a few key files. With the facts,
     * this is what the drafter reads, so the cost told before is the cost.
     *
     * @param  list<string>  $files
     */
    public static function outline(ProjectRepository $repository, Project $project, string $head, array $files): string
    {
        $outline = "Files:\n".implode("\n", array_slice($files, 0, 1500));

        foreach (self::KEY_FILES as $path) {
            $contents = $repository->show($project, $head, $path);

            if ($contents !== null) {
                $outline .= "\n\n--- {$path}\n".mb_substr($contents, 0, 6000);
            }
        }

        return $outline;
    }

    /**
     * Get the files of the app the drafter reads, without what is built,
     * stored or kept by the framework.
     *
     * @return list<string>
     */
    public static function appFiles(ProjectRepository $repository, Project $project, string $head): array
    {
        return array_values(array_filter($repository->files($project, $head), fn (string $path) => ! Str::startsWith($path, ['public/', 'storage/', 'database/migrations/', 'bootstrap/', '.'])));
    }

    /**
     * Add to each drafted area what backs it without a model: how many of
     * the app's tests ran its code, and which pages the tests opened ran it.
     * The owner reads these when they check the draft.
     *
     * @param  array{purpose: string, areas: list<array<string, mixed>>}  $draft
     * @param  list<string>  $files
     * @param  list<array{route: string, files: list<string>}>  $pages
     * @return array{purpose: string, areas: list<array<string, mixed>>}
     */
    public static function withEvidence(array $draft, array $files, ?TestMap $map, array $pages): array
    {
        $draft['areas'] = array_map(function (array $area) use ($files, $map, $pages) {
            $claims = fn (string $file) => array_any($area['paths'], fn (string $pattern) => Str::is($pattern, $file));
            $code = array_values(array_filter($files, fn (string $file) => ! str_starts_with($file, 'tests/') && $claims($file)));

            return [
                ...$area,
                'tests' => $map === null ? null : count($map->testsRunning(array_values(array_diff($code, $map->foundation())))),
                'pages' => array_values(array_map(
                    fn (array $page) => $page['route'],
                    array_filter($pages, fn (array $page) => array_any($page['files'], $claims)),
                )),
            ];
        }, $draft['areas']);

        return $draft;
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
                'rules' => self::sourcedRules(is_array($area['rules'] ?? null) ? $area['rules'] : [], $files),
            ];
        }

        return ['purpose' => trim((string) ($draft['purpose'] ?? '')), 'areas' => array_values($areas)];
    }

    /**
     * Keep the rules that name where the app enforces them: a file of the
     * app, with or without a line. A rule nothing backs is a guess.
     *
     * @param  array<mixed>  $rules
     * @param  list<string>  $files
     * @return list<string>
     */
    protected static function sourcedRules(array $rules, array $files): array
    {
        return array_values(array_filter(array_map(function (mixed $rule) use ($files) {
            $source = is_array($rule) ? Str::before(trim((string) ($rule['source'] ?? '')), ':') : '';
            $text = is_array($rule) ? trim((string) ($rule['rule'] ?? '')) : '';

            return $text !== '' && in_array($source, $files, true) ? $text : null;
        }, $rules)));
    }
}
