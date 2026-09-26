<?php

namespace App\Actions\VisualEditing;

use App\Enums\PreviewStatus;
use App\Models\Project;
use App\VisualEditing\SourceLocation;
use InvalidArgumentException;

class InspectSelection
{
    public function __construct(private InspectElement $inspectElement) {}

    /**
     * Describe the element the owner selected in the project's running
     * copy, or nothing when the copy is not running or the selection is not
     * a place in the project.
     *
     * @return array<string, mixed>|null
     */
    public function handle(Project $project, ?string $target, bool $instance): ?array
    {
        $preview = $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        if ($preview === null || $preview->status !== PreviewStatus::Ready) {
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
