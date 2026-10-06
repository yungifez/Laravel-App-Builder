<?php

namespace App\Listeners;

use App\Actions\Previews\WarmChangePreview;
use App\Enums\RunStatus;
use App\Events\RunStatusChanged;

class LetWarmPreviewGo
{
    public function __construct(private WarmChangePreview $warmChangePreview) {}

    /**
     * A first version that stopped before it was built leaves its warm
     * preview with nothing to show, so it goes rather than hold a server.
     */
    public function handle(RunStatusChanged $event): void
    {
        $featureRequest = $event->run->featureRequest;

        if ($featureRequest !== null && ($event->to->finished() || $event->to === RunStatus::NeedsUserDecision)) {
            rescue(fn () => $this->warmChangePreview->release($featureRequest->refresh()));
        }
    }
}
