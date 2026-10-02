<?php

namespace App\Actions\VisualEditing;

use App\Enums\VerificationStatus;
use App\Models\Project;
use App\Models\VisualEdit;
use App\VisualEditing\DesignDrafts;

class DescribeDesignEdits
{
    public function __construct(
        private DesignDrafts $designDrafts,
        private CommitDesignEdits $commitDesignEdits,
    ) {}

    /**
     * Describe the design edits that wait on the app to be kept, or null
     * when none wait. A problem from checking them is told until the owner
     * edits again.
     *
     * @return array{edits: int, checking: bool, problem: string|null}|null
     */
    public function handle(Project $project): ?array
    {
        $draft = $this->designDrafts->find($project);

        if ($draft === null || trim((string) $draft->patch) === '') {
            return null;
        }

        $verification = $draft->verifications()->latest('id')->first();
        $checking = in_array($verification?->status, [VerificationStatus::Queued, VerificationStatus::Running], true);
        $problem = null;

        if ($verification !== null && ! $checking && $draft->updated_at !== null && $verification->created_at?->greaterThanOrEqualTo($draft->updated_at)) {
            $problem = (string) match (true) {
                $verification->interrupted => __('This is our fault: the checks stopped on our side. Keep your edits again to check them again.'),
                $verification->status === VerificationStatus::Errored => __('Your edits could not be checked: :reason', ['reason' => $verification->error]),
                ! $this->commitDesignEdits->cleared($verification) => __('Your edits would break part of your app, so they are not kept. Undo the edit that did it, or change it.'),
                // Cleared, but the app changed while they were checked.
                default => __('Your app changed while your edits were checked. Keep them again to check them on your app as it is now.'),
            };
        }

        return [
            'edits' => VisualEdit::query()->where('feature_request_id', $draft->id)->whereNull('reverted_at')->count(),
            'checking' => $checking,
            'problem' => $problem,
        ];
    }
}
