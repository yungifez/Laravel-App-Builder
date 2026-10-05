<?php

namespace App\Actions\Changes;

use App\Actions\Previews\RequestProjectPreview;
use App\Enums\PreviewStatus;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * Open the app after the owner keeps a change, so the pane they tried it in
 * stays on their app rather than closing. A running app is rebuilt with the
 * change already (RebuildEditablePreview).
 */
class OpenKeptApp
{
    public function __construct(private RequestProjectPreview $requestProjectPreview) {}

    /**
     * @throws ValidationException when the app cannot be read.
     */
    public function handle(Project $project): void
    {
        $open = $project->previews()
            ->whereNull('feature_request_id')
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->exists();

        if (! $open) {
            $this->requestProjectPreview->handle($project);
        }
    }
}
