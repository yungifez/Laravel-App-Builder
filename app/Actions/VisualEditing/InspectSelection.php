<?php

namespace App\Actions\VisualEditing;

use App\Actions\Previews\ReadPreviewLog;
use App\Models\Project;
use App\VisualEditing\SourceLocation;
use InvalidArgumentException;

class InspectSelection
{
    public function __construct(private InspectElement $inspectElement, private ReadPreviewLog $readPreviewLog) {}

    /**
     * Describe the element the owner selected in the app on show (the copy
     * of a change, while they design one), or nothing when it is not
     * running or the selection is not a place in the project.
     *
     * @return array<string, mixed>|null
     */
    public function handle(Project $project, ?string $target, bool $instance): ?array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview === null || ! $preview->editable) {
            return null;
        }

        try {
            $location = SourceLocation::parse((string) $target, $instance);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->inspectElement->handle($preview, $location);
    }
}
