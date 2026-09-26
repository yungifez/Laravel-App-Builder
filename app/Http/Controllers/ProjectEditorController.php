<?php

namespace App\Http\Controllers;

use App\Actions\Previews\DescribeProjectPreview;
use App\Actions\VisualEditing\InspectElement;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Models\Project;
use App\Models\VisualEdit;
use App\VisualEditing\SourceLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ProjectEditorController extends Controller
{
    /**
     * Show the project running, where the owner can point at a part of it
     * and change how it looks. The selected element is loaded on request.
     */
    public function show(Request $request, Project $project, DescribeProjectPreview $describePreview, InspectElement $inspectElement): Response
    {
        Gate::authorize('view', $project);

        $preview = $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        return Inertia::render('projects/Editor', [
            'project' => $project->only('id', 'name'),
            'preview' => $describePreview->handle($project),
            'element' => Inertia::optional(fn () => $this->inspect($request, $preview, $inspectElement)),
            'edits' => $project->visualEdits()->latest('id')->limit(10)->get()
                ->map(fn (VisualEdit $edit) => [
                    'id' => $edit->id,
                    'tag' => $edit->tag,
                    'device' => $edit->device,
                    'properties' => array_keys($edit->changes),
                    'created_at' => $edit->created_at?->toIso8601String(),
                    'reverted_at' => $edit->reverted_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Describe the element the owner selected, or nothing when the
     * selection is not a place in the project.
     *
     * @return array<string, mixed>|null
     */
    protected function inspect(Request $request, ?Preview $preview, InspectElement $inspectElement): ?array
    {
        if ($preview === null || $preview->status !== PreviewStatus::Ready) {
            return null;
        }

        try {
            $location = SourceLocation::parse((string) $request->query('target'), $request->boolean('instance'));
        } catch (InvalidArgumentException) {
            return null;
        }

        return $inspectElement->handle($preview, $location);
    }
}
