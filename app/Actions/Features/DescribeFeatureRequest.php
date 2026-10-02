<?php

namespace App\Actions\Features;

use App\Actions\Projects\ConnectOwnTool;
use App\Actions\Runs\DescribeRunProgress;
use App\Actions\Runs\KeepTryingRun;
use App\Actions\Runs\NarrateWork;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\FeatureRequestStatus;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Features\NewCode;
use App\Features\OwnerWording;
use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Projects\ProjectRepository;
use App\Runs\Drivers\WorkerDriver;
use App\Runs\Plan;
use Illuminate\Support\Str;

class DescribeFeatureRequest
{
    public function __construct(
        private DescribeRunProgress $describeRunProgress,
        private NarrateWork $narrateWork,
        private DescribeProof $describeProof,
        private ProjectRepository $repository,
    ) {}

    /**
     * Describe a change for a page that shows it: the request, its latest
     * run with its plan and review, the checks and what they prove, the
     * trial copy and the follow-ups. Only what the owner may see leaves here: how changes are
     * made is ours and stays on the server (see OwnerWording).
     *
     * @return array<string, mixed>
     */
    public function handle(FeatureRequest $featureRequest): array
    {
        $parent = $featureRequest->parent;

        return [
            'project' => ['id' => $featureRequest->project->uuid, 'name' => $featureRequest->project->name],
            'featureRequest' => [
                'id' => $featureRequest->uuid,
                'prompt' => $featureRequest->prompt,
                // What a change made in the background does, said in place
                // of the words the owner never wrote.
                'background' => $featureRequest->background(),
                'images' => $this->images($featureRequest),
                'status' => $featureRequest->status->value,
                'summary' => $featureRequest->summary,
                'error' => $featureRequest->status === FeatureRequestStatus::Failed
                    ? OwnerWording::failure($featureRequest->error)
                    : OwnerWording::message($featureRequest->error),
                'target_step' => $parent === null || $featureRequest->target_step === null
                    ? null
                    : $parent->step($featureRequest->target_step),
                'steps' => $featureRequest->steps ?? [],
                'files' => $this->files($featureRequest),
                'commit_sha' => $featureRequest->commit_sha,
                // Tests it adds keep what it does working through every
                // later change; said when it is kept.
                'tests_added' => count(PatchSummary::addedTests($featureRequest->patch)),
                'accepted_at' => $featureRequest->accepted_at?->toIso8601String(),
                'revert_sha' => $featureRequest->revert_sha,
                'reverted_at' => $featureRequest->reverted_at?->toIso8601String(),
                'can_accept' => $featureRequest->status === FeatureRequestStatus::Generated
                    && $featureRequest->commit_sha === null
                    && $featureRequest->latestRun?->status === RunStatus::Completed,
                'can_retry' => RetryFeatureRequest::retryable($featureRequest),
                'can_keep_trying' => KeepTryingRun::possible($featureRequest),
                'can_continue' => RequestFollowUp::continuable($featureRequest),
                // Once the owner's tool writes every change, it needs no
                // connection of its own for this one.
                'can_work_yourself' => ! ConnectOwnTool::connected($featureRequest->project) && HandChangeToOwner::available($featureRequest),
            ],
            'parent' => $parent === null ? null : ['id' => $parent->uuid, 'prompt' => $parent->prompt],
            'earlier' => $this->earlier($featureRequest),
            'verification' => $this->latestVerification($featureRequest),
            'proof' => $this->describeProof->handle($featureRequest),
            'run' => $this->latestRun($featureRequest),
            'preview' => $this->latestPreview($featureRequest),
            'followUps' => $featureRequest->followUps()->latest()->get()
                ->map(fn (FeatureRequest $followUp) => [
                    'id' => $followUp->uuid,
                    'prompt' => $followUp->prompt,
                    'status' => $followUp->status->value,
                    'target_step' => $followUp->target_step,
                ]),
        ];
    }

    /**
     * Get the messages this change follows up on, oldest first, so the chat
     * reads as one conversation.
     *
     * @return list<array{id: string, prompt: string, summary: string|null, status: string}>
     */
    protected function earlier(FeatureRequest $featureRequest): array
    {
        $earlier = [];

        for ($request = $featureRequest->parent; $request !== null; $request = $request->parent) {
            array_unshift($earlier, [
                'id' => $request->uuid,
                'prompt' => $request->prompt,
                'images' => $this->images($request),
                'summary' => $request->summary,
                'status' => $request->status->value,
            ]);
        }

        return $earlier;
    }

    /**
     * Get the pictures the owner attached to a request, to show them.
     *
     * @return list<array{url: string, name: string}>
     */
    protected function images(FeatureRequest $featureRequest): array
    {
        return array_map(fn (array $image, int $index) => [
            'url' => route('feature-requests.images.show', [$featureRequest, $index]),
            'name' => $image['name'],
        ], $featureRequest->images ?? [], array_keys($featureRequest->images ?? []));
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
            'id' => $verification->uuid,
            'status' => $verification->status->value,
            // Package advice is said in the proof, never listed as a check.
            'results' => array_values(array_map($this->checkResult(...), array_filter($verification->results ?? [], fn (array $result) => $result['stage'] !== 'security'))),
            'error' => OwnerWording::message($verification->error),
            'started_at' => $verification->started_at?->toIso8601String(),
            'finished_at' => $verification->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Get what the owner might ask for next: the planner's ideas, after
     * closing the gap the checks found when some of the new code is run by
     * no test, since that one keeps the change safe to build on.
     *
     * @return list<string>
     */
    protected function next(Run $run): array
    {
        $ideas = $run->plan['next'] ?? [];

        $newCode = $run->featureRequest->verifications()->latest('id')->first()?->evidence['new_code'] ?? null;

        // The checks measure the new code after the review's map, so their
        // answer wins when there is one.
        if ($newCode !== null ? NewCode::gap($newCode) : ($run->review['classification']['observed']['unmapped'] ?? []) !== []) {
            $ideas = [__('Add tests for the new code nothing checks yet'), ...$ideas];
        }

        return array_slice($ideas, 0, 3);
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
            'id' => $run->uuid,
            'status' => $run->status->value,
            // A stop the owner did not ask for is ours, and says so.
            'error' => in_array($run->status, [RunStatus::Failed, RunStatus::NeedsUserDecision], true)
                ? OwnerWording::failure($run->error)
                : OwnerWording::message($run->error),
            'question' => $run->status === RunStatus::NeedsUserDecision ? $run->question : null,
            // Stopped because it found nothing to change: what it checked
            // and why, in its own words, so a fix for something that is
            // not broken does not read as a failure.
            'found_nothing' => $run->status === RunStatus::NeedsUserDecision && str_starts_with((string) $run->error, 'The run finished without changing')
                ? ($run->events()->where('type', 'build_finished')->latest('sequence')->first()?->data['account'] ?? null)
                : null,
            'answers' => $run->answers ?? [],
            // The owner's own Claude Code or Codex writes the change. Until it
            // hands the change back, the thread says how to connect it.
            'yours' => $run->driver !== 'worker' ? null : [
                // From the hand-over until their change arrives, so they can
                // connect while the change is still being planned. A tool
                // connected to the whole app gets only what our planner
                // decided to build, never a question we answer ourselves.
                'waiting' => in_array($run->status, ConnectOwnTool::connected($featureRequest->project) ? [RunStatus::Implementing] : [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing], true)
                    && app(WorkerDriver::class)->submission($run) === null,
                // Their tool handed a change back, so the thread can say who
                // wrote it.
                'wrote' => app(WorkerDriver::class)->submission($run) !== null,
                'address' => route('mcp.task'),
                // What their tool calls the connection: the app's own name.
                'name' => Str::slug($featureRequest->project->name) ?: 'app',
                // Their tool is connected to the whole app and picks the
                // change up itself.
                'whole_app' => ConnectOwnTool::connected($featureRequest->project),
            ],
            'plan' => $run->plan === null ? null : [
                'summary' => $run->plan['summary'],
                'answer' => $run->plan['answer'] ?? null,
                'acceptance_criteria' => $run->plan['acceptance_criteria'],
                'assumptions' => $run->plan['assumptions'],
                'understood_as' => $run->plan['understood_as'] ?? null,
                'current_behavior' => $run->plan['current_behavior'] ?? null,
                'preserve' => array_column(Plan::fromArray($run->plan)->preserve, 'statement'),
                'next' => $this->next($run),
                // How the change serves the owner's goal, when it does.
                'goal' => $run->plan['goal'] ?? null,
            ],
            'review' => $this->review($run),
            'progress' => $this->describeRunProgress->handle($run),
            'work' => $this->narrateWork->handle($run, $this->describeRunProgress->live($run)['story'] ?? null),
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
     * Get the latest preview for the page. A copy the owner can design on
     * also says which version of the change it runs and whether an edit is
     * still going in, as the app's own preview does.
     *
     * @return array<string, mixed>|null
     */
    protected function latestPreview(FeatureRequest $featureRequest): ?array
    {
        $preview = $featureRequest->previews()->latest('id')->first();

        return $preview === null ? null : [
            'id' => $preview->uuid,
            'status' => $preview->status->value,
            'error' => OwnerWording::message($preview->error),
            'url' => $preview->url(),
            'expires_at' => $preview->expires_at?->toIso8601String(),
            'editable' => $preview->editable,
            'origin' => rtrim($preview->url(), '/'),
            'revision' => $preview->revision,
            'updating' => $preview->editable && $preview->status === PreviewStatus::Ready && $preview->error === null
                && $this->repository->hasBranch($featureRequest->project, $featureRequest->designBranch())
                && $preview->revision !== $this->repository->head($featureRequest->project, $featureRequest->designBranch()),
        ];
    }
}
