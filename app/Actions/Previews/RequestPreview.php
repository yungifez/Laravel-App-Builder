<?php

namespace App\Actions\Previews;

use App\Enums\FeatureRequestStatus;
use App\Enums\PreviewStatus;
use App\Jobs\StartPreview;
use App\Models\FeatureRequest;
use App\Models\Preview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestPreview
{
    public function __construct(private StopPreview $stopPreview) {}

    /**
     * Start a preview of the request's change, replacing any running one.
     *
     * @throws ValidationException when the request has no change yet.
     */
    public function handle(FeatureRequest $featureRequest): Preview
    {
        if ($featureRequest->status !== FeatureRequestStatus::Generated) {
            throw ValidationException::withMessages([
                'preview' => __('Only a generated change can be previewed.'),
            ]);
        }

        $featureRequest->previews()
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->each(fn (Preview $preview) => $this->stopPreview->handle($preview));

        return DB::transaction(function () use ($featureRequest) {
            $preview = $featureRequest->previews()->create([
                'project_id' => $featureRequest->project_id,
                'host' => 'p'.Str::lower(Str::random(31)),
                'status' => PreviewStatus::Starting,
                'expires_at' => now()->addMinutes((int) config('builder.preview.max_minutes')),
            ]);

            StartPreview::dispatch($preview)->afterCommit();

            return $preview;
        });
    }
}
