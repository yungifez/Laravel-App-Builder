<?php

namespace App\Actions\VisualEditing;

use App\Actions\Context\ReadProjectContext;
use App\Context\Capability;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Projects\ProjectRepository;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TailwindClasses;
use App\VisualEditing\TemplateElement;
use Illuminate\Support\Str;

class InspectElement
{
    public function __construct(
        private ProjectRepository $repository,
        private ReadProjectContext $readProjectContext,
        private FollowLocation $followLocation,
    ) {}

    /**
     * Describe the element the owner selected in an editable preview: what
     * it is part of (from the project notes, without a model), how it looks
     * on each device, and whether its look can be changed in place.
     *
     * The element is read from the project's latest commit. When the file
     * changed since the preview was built, the element is followed through
     * the changed lines, so the owner can keep editing while the preview
     * rebuilds. Only an element whose own lines were rewritten waits for
     * the rebuild.
     *
     * @return array<string, mixed>
     */
    public function handle(Preview $preview, SourceLocation $location): array
    {
        $project = $preview->project;
        $head = $this->repository->head($project);
        $contents = $this->repository->show($project, $head, $location->file);
        $followed = $preview->revision === null ? $location : $this->followLocation->handle($project, $preview->revision, $head, $location);
        $stale = $contents !== null && $followed === null;
        $element = $contents === null || $followed === null ? null : TemplateElement::at($contents, $followed->line, $followed->column);
        $classes = $element?->classes['value'] ?? '';

        return [
            'target' => (string) $location,
            'file' => $location->file,
            'line' => $location->line,
            'tag' => $element->tag ?? null,
            'instance' => $location->instance,
            'shared' => $location->instance || $element === null ? null : $this->sharedComponent($preview, $head, $location->file),
            'editable' => $element?->editable() ?? false,
            'reason' => match (true) {
                // A rebuild that failed will not bring the preview up to date.
                $stale && $preview->error !== null => 'behind',
                $stale => 'updating',
                $element === null => 'not_found',
                ! $element->editable() => 'dynamic',
                default => null,
            },
            'classes' => $classes,
            'values' => TailwindClasses::effective($classes),
            'area' => $this->area($preview, $location->file),
            'origin' => $followed === null ? null : $this->origin($preview, $head, $location->file, $followed->line),
            'revision' => $head,
        ];
    }

    /**
     * Get the area of the app the file belongs to, from the project notes,
     * with the other areas its Effects say a change here may also affect,
     * so the owner sees the reach of a change before asking for it. Each
     * says whether tests check it, since every change runs them all.
     *
     * @return array{key: string, name: string, summary: string|null, rules: list<string>, behaviors: list<string>, affects: list<array{name: string, tested: bool}>}|null
     */
    protected function area(Preview $preview, string $file): ?array
    {
        $context = $this->readProjectContext->current($preview->project);

        /** @var Capability|null $capability */
        $capability = collect($context->capabilities)->first(fn (Capability $capability) => $capability->claims($file));

        if ($capability === null) {
            return null;
        }

        $affects = [];

        foreach ($capability->effects as $effect) {
            $other = $context->capabilities[$effect->to] ?? null;

            if ($other !== null && $other->key !== $capability->key) {
                $affects[$other->key] = ['name' => $other->name, 'tested' => $other->testFiles !== []];
            }
        }

        return [
            'key' => $capability->key,
            'name' => $capability->name,
            'summary' => $capability->summary,
            'rules' => $capability->rules(),
            'behaviors' => array_column($capability->behaviors, 'name'),
            'affects' => array_values($affects),
        ];
    }

    /**
     * Get the kept request behind the element's line, so the owner can see
     * why a part is there in their own words. The line's history is
     * followed back through later edits and moves. Requests later undone
     * are skipped.
     *
     * @return array{id: int, how: 'added'|'changed', asked: string, at: string|null, decided: array{question: string, answer: string}|null}|null
     */
    protected function origin(Preview $preview, string $head, string $file, int $line): ?array
    {
        $result = $this->repository->git($preview->project, ['log', '-s', '--format=%H', '-L', "{$line},{$line}:{$file}", $head], throw: false, timeout: 10);
        $commits = $result->successful() ? array_values(preg_grep('/^[0-9a-f]{40}$/', explode("\n", trim($result->output()))) ?: []) : [];

        if ($commits === []) {
            return null;
        }

        $kept = FeatureRequest::query()
            ->whereBelongsTo($preview->project)
            ->whereIn('commit_sha', $commits)
            ->whereNotNull('accepted_at')
            ->whereNull('reverted_at')
            ->with('latestRun')
            ->get()
            ->keyBy('commit_sha');

        // The oldest commit made the line. When a kept request made it, the
        // part was added for that request; otherwise it came from elsewhere
        // and the latest kept request that reworked it is named instead.
        $made = $kept->get(end($commits));
        $latest = collect($commits)->first(fn (string $commit) => $kept->has($commit));
        $featureRequest = $made ?? ($latest === null ? null : $kept->get($latest));

        if ($featureRequest === null) {
            return null;
        }

        $decided = collect($featureRequest->latestRun->answers ?? [])->last(fn (array $answer) => $answer['decided_by'] === 'owner');

        return [
            'id' => $featureRequest->id,
            'how' => $made === null ? 'changed' : 'added',
            'asked' => Str::limit($featureRequest->prompt, 140),
            'at' => $featureRequest->accepted_at?->toIso8601String(),
            'decided' => $decided === null ? null : ['question' => $decided['question'], 'answer' => $decided['answer']],
        ];
    }

    /**
     * When the file is a component, get its name and how many other files
     * use it: changing it changes every one of them.
     *
     * @return array{name: string, uses: int}|null
     */
    protected function sharedComponent(Preview $preview, string $head, string $file): ?array
    {
        if (! str_contains($file, '/components/') || ! str_ends_with($file, '.vue')) {
            return null;
        }

        $name = pathinfo($file, PATHINFO_FILENAME);
        $result = $this->repository->git($preview->project, ['grep', '-l', '-E', "<{$name}([[:space:]/>]|$)", $head, '--', '*.vue'], throw: false);
        $uses = count(array_filter(explode("\n", trim($result->output()))));

        return ['name' => $name, 'uses' => $uses];
    }
}
