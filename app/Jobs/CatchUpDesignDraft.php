<?php

namespace App\Jobs;

use App\Models\FeatureRequest;
use App\Projects\Exceptions\RepositoryConflict;
use App\VisualEditing\DesignDrafts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CatchUpDesignDraft implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public FeatureRequest $draft) {}

    /**
     * Move the waiting design edits onto the app as it is now. Run apart
     * from the commit that moved the app, which still holds the
     * repository. When the edits no longer fit, the draft stays as it was,
     * and keeping it says why.
     */
    public function handle(DesignDrafts $designDrafts): void
    {
        if ($designDrafts->find($this->draft->project)?->is($this->draft) !== true) {
            return;
        }

        try {
            $designDrafts->catchUp($this->draft);
        } catch (RepositoryConflict) {
            // Left as it was; see above.
        }
    }
}
