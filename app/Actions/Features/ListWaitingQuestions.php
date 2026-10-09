<?php

namespace App\Actions\Features;

use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListWaitingQuestions
{
    /**
     * Get the owner's changes that wait on their answer, newest first, as
     * the chat shows them. A note read and then left, or never sent, does
     * not make the question go away: the change still needs the owner.
     *
     * @param  array<int, int>  $told  Changes with an unread note about their question, already counted
     * @return Collection<int, array{id: string, title: string, body: string, app: string|null, href: string, created_at: string|null}>
     */
    public function handle(User $user, array $told = []): Collection
    {
        return FeatureRequest::query()
            ->where('user_id', $user->id)
            ->whereNull('dismissed_at')
            ->whereNull('tidy')
            ->whereNotIn('id', $told)
            ->whereHas('latestRun', fn (Builder $run) => $run
                ->where('status', RunStatus::NeedsUserDecision)
                ->where(fn (Builder $asks) => $asks
                    ->whereNotNull('question')
                    ->orWhereIn('stop_reason', [StopReason::Question, StopReason::FindingProposed])))
            ->with(['latestRun', 'project'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (FeatureRequest $change) => [
                'id' => $change->uuid,
                'title' => (string) __('I have a question about your change'),
                'body' => str($change->latestRun?->question['text'] ?? $change->prompt)->squish()->limit(120)->toString(),
                'app' => $change->project?->name,
                'href' => route('projects.show', ['project' => $change->project, 'change' => $change->uuid]),
                'created_at' => $change->latestRun?->updated_at?->toIso8601String(),
            ])
            ->values();
    }
}
