<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Project;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OpenSharedApp
{
    public function __construct(
        private GrantPreviewAccess $grantPreviewAccess,
        private RequestProjectPreview $requestProjectPreview,
    ) {}

    /**
     * Find the app a shared link is for, and the address that opens it
     * for the person holding the link. While the app is asleep it is
     * started, and there is no address yet.
     *
     * @return array{project: Project, url: string|null}
     *
     * @throws NotFoundHttpException when the link is wrong, ended or expired.
     */
    public function handle(string $token): array
    {
        $project = Project::query()
            ->where('share_token_hash', hash('sha256', $token))
            ->where('share_expires_at', '>', now())
            ->first();

        if ($project === null) {
            throw new NotFoundHttpException;
        }

        $preview = $project->previews()
            ->whereNull('feature_request_id')
            ->where('editable', true)
            ->latest('id')
            ->first();

        if ($preview?->status === PreviewStatus::Ready) {
            return ['project' => $project, 'url' => $this->grantPreviewAccess->handle($preview, shared: true, shareTokenHash: $project->share_token_hash)];
        }

        // Started once: everyone who opens the link while it starts waits
        // for the same app.
        if ($preview?->status !== PreviewStatus::Starting) {
            $this->requestProjectPreview->handle($project);
        }

        return ['project' => $project, 'url' => null];
    }
}
