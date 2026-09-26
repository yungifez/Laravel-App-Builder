<?php

namespace App\Actions\Features;

use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\OwnerWording;
use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Runs\Plan;
use Illuminate\Support\Str;

class DescribeFeatureRequest
{
    /**
     * Describe a change for a page that shows it: the request, its latest
     * run with its plan and review, the checks, the trial copy and the
     * follow-ups. Only what the owner may see leaves here: how changes are
     * made is ours and stays on the server (see OwnerWording).
     *
     * @return array<string, mixed>
     */
    public function handle(FeatureRequest $featureRequest): array
    {
        $parent = $featureRequest->parent;

        return [
            'project' => $featureRequest->project->only('id', 'name'),
            'featureRequest' => [
                'id' => $featureRequest->id,
                'prompt' => $featureRequest->prompt,
                'status' => $featureRequest->status->value,
                'summary' => $featureRequest->summary,
                'error' => OwnerWording::message($featureRequest->error),
                'target_step' => $parent === null || $featureRequest->target_step === null
                    ? null
                    : $parent->step($featureRequest->target_step),
                'steps' => $featureRequest->steps ?? [],
                'files' => $this->files($featureRequest),
                'commit_sha' => $featureRequest->commit_sha,
                'accepted_at' => $featureRequest->accepted_at?->toIso8601String(),
                'revert_sha' => $featureRequest->revert_sha,
                'reverted_at' => $featureRequest->reverted_at?->toIso8601String(),
                'can_accept' => $featureRequest->status === FeatureRequestStatus::Generated
                    && $featureRequest->commit_sha === null
                    && $featureRequest->latestRun?->status === RunStatus::Completed,
                'can_retry' => RetryFeatureRequest::retryable($featureRequest),
            ],
            'parent' => $parent?->only('id', 'prompt'),
            'verification' => $this->latestVerification($featureRequest),
            'run' => $this->latestRun($featureRequest),
            'preview' => $this->latestPreview($featureRequest),
            'followUps' => $featureRequest->followUps()->latest()->get()
                ->map(fn (FeatureRequest $followUp) => [
                    'id' => $followUp->id,
                    'prompt' => $followUp->prompt,
                    'status' => $followUp->status->value,
                    'target_step' => $followUp->target_step,
                ]),
        ];
    }

    /**
     * Get the latest verification run for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestVerification(FeatureRequest $featureRequest): ?array
    {
        $verification = $featureRequest->verifications()->latest('id')->first();

        return $verification === null ? null : [
            'id' => $verification->id,
            'status' => $verification->status->value,
            'results' => array_map($this->checkResult(...), $verification->results ?? []),
            'error' => OwnerWording::message($verification->error),
            'started_at' => $verification->started_at?->toIso8601String(),
            'finished_at' => $verification->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Get the latest construction run and its log for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestRun(FeatureRequest $featureRequest): ?array
    {
        $run = $featureRequest->latestRun;

        return $run === null ? null : [
            'id' => $run->id,
            'status' => $run->status->value,
            'error' => OwnerWording::message($run->error),
            'question' => $run->status === RunStatus::NeedsUserDecision ? $run->question : null,
            'answers' => $run->answers ?? [],
            'plan' => $run->plan === null ? null : [
                'summary' => $run->plan['summary'],
                'answer' => $run->plan['answer'] ?? null,
                'acceptance_criteria' => $run->plan['acceptance_criteria'],
                'assumptions' => $run->plan['assumptions'],
                'understood_as' => $run->plan['understood_as'] ?? null,
                'current_behavior' => $run->plan['current_behavior'] ?? null,
                'preserve' => array_column(Plan::fromArray($run->plan)->preserve, 'statement'),
            ],
            'review' => $this->review($run),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'log' => $run->events()->get()
                ->map(fn (RunEvent $event) => [
                    'sequence' => $event->sequence,
                    'text' => OwnerWording::event($event),
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->filter(fn (array $entry) => $entry['text'] !== null)
                ->values(),
        ];
    }

    /**
     * Get the files the change touched. Older changes carried the notes
     * inside the app; those are ours and are left out.
     *
     * @return list<array{path: string, additions: int, deletions: int, diff: string}>
     */
    protected function files(FeatureRequest $featureRequest): array
    {
        $hidden = [ProjectContext::LEGACY_DIRECTORY.'/', ProjectNotes::directory().'/'];

        return array_values(array_filter(
            PatchSummary::files($featureRequest->patch),
            fn (array $file) => ! Str::startsWith($file['path'], $hidden),
        ));
    }

    /**
     * Show one check the way the owner's own developer would see it. Putting
     * the change in place and our own extra tests are ours, so they show
     * without their output.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function checkResult(array $result): array
    {
        return match ($result['stage'] ?? null) {
            'apply' => [...$result, 'name' => __('Put the change in place'), 'output' => ''],
            'acceptance' => [...$result, 'name' => __('Extra checks'), 'output' => ''],
            default => $result,
        };
    }

    /**
     * Get the run's review for the owner: the behaviour changes and where the
     * change landed by area, with the areas' names.
     *
     * @return array<string, mixed>|null
     */
    protected function review(Run $run): ?array
    {
        if ($run->review === null) {
            return null;
        }

        $names = array_column($run->context['outline'] ?? [], 'name', 'key');
        $classification = $run->review['classification'];
        $areas = fn (array $files) => array_map(
            fn (string $key) => ['key' => $key, 'name' => $names[$key] ?? $key, 'files' => $files[$key]],
            array_keys($files),
        );

        return [
            'summary' => OwnerWording::message($run->review['summary'], ''),
            'changes' => array_map(fn (array $change) => [
                ...$change,
                'area_name' => $change['area'] === null ? null : ($names[$change['area']] ?? $change['area']),
            ], $run->review['changes']),
            'areas' => [
                'requested' => $areas($classification['requested']),
                'may_also_affect' => $areas($classification['may_also_affect']),
                'unexpected' => $areas($classification['unexpected']),
            ],
            'preserved' => array_map(fn (array $item) => [
                ...$item,
                'area_name' => $item['area'] === null ? null : ($names[$item['area']] ?? $item['area']),
            ], $run->review['preserved'] ?? []),
            'verified' => $run->review['verified'] ?? [],
            'unclaimed' => $classification['unclaimed'],
            'context_updates' => $classification['context_updates'],
        ];
    }

    /**
     * Get the latest preview for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestPreview(FeatureRequest $featureRequest): ?array
    {
        $preview = $featureRequest->previews()->latest('id')->first();

        return $preview === null ? null : [
            'id' => $preview->id,
            'status' => $preview->status->value,
            'error' => OwnerWording::message($preview->error),
            'url' => $preview->url(),
            'expires_at' => $preview->expires_at?->toIso8601String(),
        ];
    }
}
