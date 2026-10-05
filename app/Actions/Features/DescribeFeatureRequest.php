<?php

namespace App\Actions\Features;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Projects\ConnectOwnTool;
use App\Actions\Publishing\DescribeUnpublished;
use App\Actions\Runs\DescribeRunProgress;
use App\Actions\Runs\KeepTryingRun;
use App\Actions\Runs\NarrateWork;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Features\LiftedLimit;
use App\Features\NewCode;
use App\Features\OwnerWording;
use App\Features\PatchSummary;
use App\Features\RepeatedFailure;
use App\Models\Experiment;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Projects\ProjectRepository;
use App\Runs\Drivers\WorkerDriver;
use App\Runs\Plan;
use App\Scaffolding\ShapeWording;
use Illuminate\Support\Str;

class DescribeFeatureRequest
{
    /**
     * Each check, in the owner's words, as the subject of a sentence.
     */
    protected const CHECKS = [
        'Tests' => 'Your app\'s tests',
        'Static analysis' => 'Reading the code for mistakes',
        'PHP formatting' => 'Checking the code is tidy',
        'Frontend format and lint' => 'Checking the screens\' code is tidy',
        'TypeScript' => 'Reading the screens\' code for mistakes',
    ];

    public function __construct(
        private DescribeRunProgress $describeRunProgress,
        private NarrateWork $narrateWork,
        private DescribeProof $describeProof,
        private ProjectRepository $repository,
        private DescribeUnpublished $describeUnpublished,
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
        $sameWay = RepeatedFailure::of($featureRequest);

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
                'error' => $this->sameWay($sameWay, LiftedLimit::reason($featureRequest, $featureRequest->status === FeatureRequestStatus::Failed
                    ? OwnerWording::failure($featureRequest->error)
                    : OwnerWording::message($featureRequest->error))),
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
                // Undoing a change does not take it off the live app: the
                // owner is told, and can put the app online again.
                'still_online' => $this->stillOnline($featureRequest),
                // Undoing never runs a migration's down(): what the app
                // stored stays, and the owner is told so.
                'kept_data' => $featureRequest->reverted_at !== null && $this->addsMigration($featureRequest->patch),
                'can_accept' => $featureRequest->status === FeatureRequestStatus::Generated
                    && $featureRequest->commit_sha === null
                    && $featureRequest->latestRun?->status === RunStatus::Completed
                    && ! RetryFeatureRequest::mustBeMadeAgain($featureRequest),
                'can_retry' => RetryFeatureRequest::retryable($featureRequest),
                // Checking or trying it again fails the same way: only
                // trying again, which makes it afresh, is offered.
                'made_again_only' => RetryFeatureRequest::mustBeMadeAgain($featureRequest),
                // The cases tested before the build, which the owner may
                // still say are not what they meant (CorrectWrittenCase).
                'written_cases' => CorrectWrittenCase::written($featureRequest),
                'can_correct_cases' => CorrectWrittenCase::open($featureRequest) && ! RetryFeatureRequest::mustBeMadeAgain($featureRequest),
                // The newer try of this change, when it was tried again: the
                // place to go on from.
                // It stopped without a change to keep: even when it cannot be
                // tried again, the owner can still ask in other words.
                'stopped' => $featureRequest->latestRun?->question === null && (
                    in_array($featureRequest->status, [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled], true)
                    || ($featureRequest->status !== FeatureRequestStatus::Generated
                        && in_array($featureRequest->latestRun?->status, [RunStatus::Failed, RunStatus::NeedsUserDecision, RunStatus::Cancelled], true))
                ),
                'tried_again' => FeatureRequest::query()->where('retry_of_id', $featureRequest->id)->latest('id')->value('uuid'),
                // It stopped just as the try before it did, so trying again
                // is no longer the first thing offered.
                'failed_same_way' => $sameWay,
                'can_keep_trying' => KeepTryingRun::possible($featureRequest),
                'can_continue' => RequestFollowUp::continuable($featureRequest),
                // An earlier change in this chat that passed and can still
                // be kept, when this one stopped.
                'keep_earlier' => $this->keepEarlier($featureRequest),
                // Once the owner's tool writes every change, it needs no
                // connection of its own for this one.
                'can_work_yourself' => ! ConnectOwnTool::connected($featureRequest->project) && HandChangeToOwner::available($featureRequest),
            ],
            'parent' => $parent === null ? null : ['id' => $parent->uuid, 'prompt' => $parent->prompt],
            'earlier' => $this->earlier($featureRequest),
            'verification' => $this->latestVerification($featureRequest),
            'proof' => $this->describeProof->handle($featureRequest),
            'run' => $this->latestRun($featureRequest, $sameWay),
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
     * Say whether an undone change is still in the live app: the newest
     * publish holds it. Gives the version to put online, and how many
     * other kept changes go online with it, so the owner is not surprised.
     *
     * @return array{head: string|null, others: int}|null
     */
    protected function stillOnline(FeatureRequest $featureRequest): ?array
    {
        if ($featureRequest->reverted_at === null) {
            return null;
        }

        $project = $featureRequest->project;
        $live = $project->deployments()->where('status', DeploymentStatus::Published)->latest('id')->first();

        if ($live === null || $live->featureRequests()->whereKey($featureRequest->id)->doesntExist()) {
            return null;
        }

        $head = $this->repository->exists($project) ? ($this->repository->head($project, Experiment::mainBranch()) ?: null) : null;
        $waiting = $this->describeUnpublished->handle($project, $head);

        return [
            'head' => $head,
            'others' => $waiting === null ? 0 : count($waiting['added']) + min($waiting['edits'], 1),
        ];
    }

    /**
     * Determine if a patch adds a database migration. A migration is known
     * by what it is, not where it lives.
     */
    protected function addsMigration(?string $patch): bool
    {
        return collect(PatchSummary::files($patch))->contains(fn (array $file) => str_contains($file['diff'], "\nnew file mode ")
            && str_ends_with($file['path'], '.php')
            && str_contains($file['diff'], 'extends Migration'));
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
     * Get the nearest earlier change in this chat that the owner can still
     * keep, unless one nearer is already kept.
     */
    protected function keepEarlier(FeatureRequest $featureRequest): ?string
    {
        for ($request = $featureRequest->parent; $request !== null; $request = $request->parent) {
            if ($request->commit_sha !== null) {
                return null;
            }

            if ($request->status === FeatureRequestStatus::Generated && $request->latestRun?->status === RunStatus::Completed) {
                return $request->uuid;
            }
        }

        return null;
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
            'failed' => $this->failedChecks($verification->results ?? []),
            'started_at' => $verification->started_at?->toIso8601String(),
            'finished_at' => $verification->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Name, in the owner's words, the checks the change made fail. A check
     * that failed the same way before the change is not the change's.
     *
     * @param  list<array<string, mixed>>  $results
     */
    protected function failedChecks(array $results): ?string
    {
        $names = collect($results)
            ->filter(fn (array $result) => $result['outcome'] === 'failed' && ! (($result['at_start'] ?? null) === 'failed' && ($result['new_problems'] ?? []) === []))
            ->map(fn (array $result): string => match ($result['stage'] ?? null) {
                'apply' => 'Putting the change in place',
                'setup' => 'Getting your app ready to check',
                default => self::CHECKS[$result['name']] ?? 'A check',
            })
            ->unique()
            ->values();

        return match ($names->count()) {
            0 => null,
            1 => "{$names[0]} failed",
            default => "{$names[0]} and ".($names->count() - 1).' more failed',
        };
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
     * Say a stop that repeats the try before it as such, in place of the
     * advice to try again.
     */
    protected function sameWay(bool $sameWay, ?string $reason): ?string
    {
        return $sameWay && $reason !== null ? RepeatedFailure::reason($reason) : $reason;
    }

    /**
     * Get the latest construction run and its log for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestRun(FeatureRequest $featureRequest, bool $sameWay = false): ?array
    {
        $run = $featureRequest->latestRun;

        return $run === null ? null : [
            'id' => $run->uuid,
            'status' => $run->status->value,
            // A stop the owner did not ask for is ours, and says so.
            'error' => $this->sameWay($sameWay, LiftedLimit::reason($featureRequest, in_array($run->status, [RunStatus::Failed, RunStatus::NeedsUserDecision], true)
                ? OwnerWording::failure($run->error)
                : OwnerWording::message($run->error))),
            'question' => $run->status === RunStatus::NeedsUserDecision ? $run->question : null,
            // Stopped because the month's AI use ran out, and it still has:
            // the owner gets a way to their plan, not just the words.
            'plan_ran_out' => $run->stop_reason === 'usage_limit' && app(MeasureUsage::class)->handle($featureRequest->project->owner)['reached'],
            // Stopped because it found nothing to change: what it checked
            // and why, in its own words, so a fix for something that is
            // not broken does not read as a failure.
            'found_nothing' => $run->status === RunStatus::NeedsUserDecision && str_starts_with((string) $run->error, 'The run finished without changing')
                ? ($run->events()->where('type', 'build_finished')->latest('sequence')->first()?->data['account'] ?? null)
                : null,
            'answers' => $run->answers ?? [],
            'kept_assumptions' => $run->kept_assumptions ?? [],
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
                // How each criterion is tried: the usual way, another way
                // and a refusal, or why one does not apply.
                'cases' => $run->plan['cases'],
                // What a new record keeps and who may use it is decided
                // for the owner like any assumption, so it is shown first.
                'assumptions' => [...app(ShapeWording::class)->describe(Plan::fromArray($run->plan)->dataShape), ...$run->plan['assumptions']],
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
            ], $run->review['preserved']),
            'verified' => $run->review['verified'],
            'coverage' => array_map(fn (array $area) => [...$area, 'name' => $names[$area['area']] ?? $area['area']], $run->review['coverage']),
            'unclaimed' => $classification['unclaimed'],
            'context_updates' => $classification['context_updates'],
            // Parts whose code changed but whose notes did not; a small fix
            // often needs none, so the owner is asked to check, not warned.
            'notes_behind' => array_map(fn (string $key) => ['key' => $key, 'name' => $names[$key] ?? $key], $classification['notes_behind']),
            // A worker's change whose notes we failed to update: our fault.
            'notes_failed' => $run->events()->where('type', 'notes_not_updated')->exists(),
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
            // One line for the owner; the output after it is for operators.
            'error' => OwnerWording::message(strtok((string) $preview->error, "\n") ?: null),
            'url' => $preview->url(),
            'expires_at' => $preview->expires_at?->toIso8601String(),
            'editable' => $preview->editable,
            'no_longer_fits' => $preview->no_longer_fits,
            'origin' => rtrim($preview->url(), '/'),
            'revision' => $preview->revision,
            'updating' => $preview->editable && $preview->status === PreviewStatus::Ready && $preview->error === null
                && $this->repository->hasBranch($featureRequest->project, $featureRequest->designBranch())
                && $preview->revision !== $this->repository->head($featureRequest->project, $featureRequest->designBranch()),
        ];
    }
}
