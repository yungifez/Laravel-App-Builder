<?php

namespace App\Actions\VisualEditing;

use App\Actions\Context\ReadProjectContext;
use App\Context\Capability;
use App\Models\Preview;
use App\Projects\ProjectRepository;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TailwindClasses;
use App\VisualEditing\TemplateElement;

class InspectElement
{
    public function __construct(
        private ProjectRepository $repository,
        private ReadProjectContext $readProjectContext,
    ) {}

    /**
     * Describe the element the owner selected in an editable preview: what
     * it is part of (from the project notes, without a model), how it looks
     * on each device, and whether its look can be changed in place.
     *
     * The element is read from the project's latest commit. When the file
     * changed since the preview was built, the preview's locations are out
     * of date, so nothing can be edited until it is rebuilt.
     *
     * @return array<string, mixed>
     */
    public function handle(Preview $preview, SourceLocation $location): array
    {
        $project = $preview->project;
        $head = $this->repository->head($project);
        $contents = $this->repository->show($project, $head, $location->file);
        $stale = $preview->revision !== null
            && $preview->revision !== $head
            && $this->repository->show($project, $preview->revision, $location->file) !== $contents;
        $element = $contents === null || $stale ? null : TemplateElement::at($contents, $location->line, $location->column);
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
                $stale => 'updating',
                $element === null => 'not_found',
                ! $element->editable() => 'dynamic',
                default => null,
            },
            'classes' => $classes,
            'values' => TailwindClasses::effective($classes),
            'area' => $this->area($preview, $head, $location->file),
            'revision' => $head,
        ];
    }

    /**
     * Get the area of the app the file belongs to, from the project notes.
     *
     * @return array{key: string, name: string, summary: string|null, rules: list<string>, behaviors: list<string>}|null
     */
    protected function area(Preview $preview, string $head, string $file): ?array
    {
        $context = $this->readProjectContext->atRevision($preview->project, $head);

        /** @var Capability|null $capability */
        $capability = collect($context->capabilities)->first(fn (Capability $capability) => $capability->claims($file));

        return $capability === null ? null : [
            'key' => $capability->key,
            'name' => $capability->name,
            'summary' => $capability->summary,
            'rules' => $capability->rules(),
            'behaviors' => array_column($capability->behaviors, 'name'),
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
