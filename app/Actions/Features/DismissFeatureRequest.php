<?php

namespace App\Actions\Features;

use App\Actions\Runs\CancelRun;
use App\Models\FeatureRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Put an ask the owner no longer needs out of the way, or bring it back. An
 * ask is a request with its follow-ups, so the mark goes on the first
 * request. Work still going on it stops, so it costs nothing more.
 */
class DismissFeatureRequest
{
    public function __construct(private CancelRun $cancelRun) {}

    /**
     * Mark the ask as not needed.
     *
     * @throws ValidationException when part of it is kept in the app.
     */
    public function handle(FeatureRequest $featureRequest): FeatureRequest
    {
        $root = $this->root($featureRequest);
        $thread = $this->thread($root);

        if ($thread->contains(fn (FeatureRequest $request) => $request->isAccepted())) {
            throw ValidationException::withMessages([
                'dismiss' => __('This is part of your app. Undo it instead.'),
            ]);
        }

        foreach ($thread as $request) {
            $run = $request->latestRun;

            if ($run !== null && ! $run->status->finished()) {
                $this->cancelRun->handle($run);
            }
        }

        $root->update(['dismissed_at' => now()]);

        return $root;
    }

    /**
     * Bring a dismissed ask back into the list.
     */
    public function restore(FeatureRequest $featureRequest): FeatureRequest
    {
        $root = $this->root($featureRequest);
        $root->update(['dismissed_at' => null]);

        return $root;
    }

    /**
     * Get the request that started the ask.
     */
    protected function root(FeatureRequest $featureRequest): FeatureRequest
    {
        $root = $featureRequest;

        while ($root->parent_id !== null) {
            $root = $root->parent()->firstOrFail();
        }

        return $root;
    }

    /**
     * Get the request with all its follow-ups, however deep.
     *
     * @return Collection<int, FeatureRequest>
     */
    protected function thread(FeatureRequest $root): Collection
    {
        $requests = FeatureRequest::query()->where('project_id', $root->project_id)->with('latestRun')->get();
        $thread = collect([$root]);
        $parents = [$root->id];

        while ($parents !== []) {
            $children = $requests->whereIn('parent_id', $parents);
            $thread = $thread->merge($children);
            $parents = $children->pluck('id')->all();
        }

        return $thread;
    }
}
