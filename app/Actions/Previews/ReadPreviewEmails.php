<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Previews\LoggedEmails;

class ReadPreviewEmails
{
    public function __construct(private ReadPreviewLog $readLog) {}

    /**
     * Get the email the app on show has sent, newest first. A preview
     * writes email to its log instead of sending it, so the owner can read
     * it here and follow its links.
     *
     * @return list<array{id: string, sent_at: string|null, from: string, to: string, subject: string, html: string|null, text: string|null}>
     */
    public function handle(Project $project): array
    {
        $preview = $this->readLog->preview($project);

        return $preview === null ? [] : LoggedEmails::in($this->readLog->handle($preview));
    }

    /**
     * Get the email a change's copy has sent, newest first, so the owner
     * trying the change can follow a sign-up or a password reset too.
     *
     * @return list<array{id: string, sent_at: string|null, from: string, to: string, subject: string, html: string|null, text: string|null}>
     */
    public function forChange(FeatureRequest $featureRequest): array
    {
        $preview = $featureRequest->previews()->latest('id')->first();

        return $preview?->status === PreviewStatus::Ready && $preview->workspace !== null
            ? LoggedEmails::in($this->readLog->handle($preview))
            : [];
    }
}
