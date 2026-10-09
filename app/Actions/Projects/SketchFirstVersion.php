<?php

namespace App\Actions\Projects;

use App\Actions\Runs\DescribeRunProgress;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What the workspace draws while the first version is made, so the owner
 * sees their app take shape at once rather than a spinner: its name in
 * its own look, and the parts it is getting. Only what is known is drawn;
 * a part shows as made once the coder has changed a file the plan gave it.
 */
class SketchFirstVersion
{
    /**
     * The app's own colour names the drawing uses, from its stylesheet.
     */
    protected const TOKENS = ['background', 'foreground', 'primary', 'muted-foreground', 'border'];

    public function __construct(
        private ProjectRepository $repository,
        private DescribeRunProgress $describeRunProgress,
    ) {}

    /**
     * @return array{name: string, look: array{background: string, foreground: string, primary: string, muted_foreground: string, border: string, radius: string|null, font: string|null}|null, parts: list<array{name: string, made: bool}>, now: string|null}
     */
    public function handle(Project $project, FeatureRequest $change, bool $building = true): array
    {
        $run = $change->latestRun;

        return [
            'name' => $project->name,
            'look' => $this->look($project),
            'parts' => $this->parts($change, $run),
            // The same words the change's own progress line says, while it is made.
            'now' => ! $building || $run === null ? null : ($this->describeRunProgress->handle($run)['text'] ?? null),
        ];
    }

    /**
     * Read the look from the app's own stylesheet, as the app draws itself.
     * One without those colours is drawn in a neutral look instead.
     *
     * @return array{background: string, foreground: string, primary: string, muted_foreground: string, border: string, radius: string|null, font: string|null}|null
     */
    protected function look(Project $project): ?array
    {
        $head = rescue(fn () => $this->repository->head($project), null, report: false);

        if ($head === null) {
            return null;
        }

        // The stylesheet only changes with a new commit, and the page asks every few seconds.
        return Cache::remember("projects:{$project->id}:look:{$head}", now()->addHour(), function () use ($project, $head) {
            $css = $this->repository->show($project, $head, ApplyDesignDirection::STYLESHEET);

            if ($css === null || preg_match('/^:root\s*\{(.*?)^\}/ms', $css, $root) !== 1) {
                return null;
            }

            $value = fn (string $name) => preg_match('/^\s*--'.preg_quote($name, '/').'\s*:\s*([^;]+);/m', $root[1], $match) === 1 ? trim($match[1]) : null;
            $colors = array_map($value, array_combine(self::TOKENS, self::TOKENS));

            if (in_array(null, $colors, true)) {
                return null;
            }

            $font = preg_match('/--font-sans:\s*([\'"]?)([^,\'";]+)\1/', $css, $match) === 1 ? trim($match[2]) : null;

            return [
                'background' => $colors['background'],
                'foreground' => $colors['foreground'],
                'primary' => $colors['primary'],
                'muted_foreground' => $colors['muted-foreground'],
                'border' => $colors['border'],
                'radius' => $value('radius'),
                'font' => $font,
            ];
        });
    }

    /**
     * The parts the first version is getting: what the owner said it
     * includes until it is planned, then the plan's own parts.
     *
     * @return list<array{name: string, made: bool}>
     */
    protected function parts(FeatureRequest $change, ?Run $run): array
    {
        $steps = $run?->plan['steps'] ?? [];

        if ($steps === []) {
            return array_map(fn (string $item) => ['name' => $item, 'made' => false], $this->includes($change));
        }

        $changed = $this->changed($run);
        $parts = [];

        foreach ($steps as $step) {
            $made = in_array($step['file'], $changed, true);
            $parts[$step['label']] = ['name' => $step['label'], 'made' => ($parts[$step['label']]['made'] ?? false) || $made];
        }

        return array_values($parts);
    }

    /**
     * What the owner said the first version includes, as the change asks it.
     *
     * @return list<string>
     */
    protected function includes(FeatureRequest $change): array
    {
        $list = Str::after((string) $change->prompt, __('It includes:'));

        if ($list === (string) $change->prompt) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line) => str_starts_with(trim($line), '- ') ? trim(Str::after(trim($line), '- ')) : '',
            explode("\n", $list),
        )));
    }

    /**
     * The files the coder has changed so far, as the change's story tells it.
     *
     * @return list<string>
     */
    protected function changed(?Run $run): array
    {
        if ($run === null) {
            return [];
        }

        $files = [];

        foreach ($run->events()->whereIn('type', ['agent_story', 'worker_tried'])->get() as $event) {
            if ($event->type === 'worker_tried') {
                array_push($files, ...array_filter((array) ($event->data['files'] ?? []), is_string(...)));

                continue;
            }

            foreach ($event->data['story'] ?? [] as $entry) {
                if (($entry['kind'] ?? null) === 'changed' && is_string($entry['file'] ?? null)) {
                    $files[] = $entry['file'];
                }
            }
        }

        return array_values(array_unique([...$files, ...($this->describeRunProgress->live($run)['changed'] ?? [])]));
    }
}
