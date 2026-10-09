<?php

namespace App\Actions\Developers;

use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\RecordDecision;
use App\Actions\Context\UpdateProjectNotes;
use App\Actions\Features\DescribeProof;
use App\Context\Capability;
use App\Context\NotesDocument;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\TestObservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Write what a developer reads when the owner asks them to look at a change
 * or at the whole app (architecture §29.3): the smallest useful slice of
 * what we know, so their hour goes on judgment, not on finding their way
 * around. Everything comes from what is recorded: the notes, the change,
 * its checks and the changes kept before it. No model writes any of it.
 *
 * It holds the owner's knowledge of their own app and the open evidence
 * only, never how we chose, scored or routed anything (§11, "Workers"):
 * the developer is ours, but the request can be downloaded and passed on.
 */
class WriteReviewRequest
{
    public function __construct(
        private ReadProjectContext $readProjectContext,
        private DescribeProof $describeProof,
    ) {}

    /**
     * Write the request for the change, or for the whole app when there is
     * no change.
     */
    public function handle(Project $project, string $question, ?FeatureRequest $featureRequest, ?string $revision): string
    {
        $sections = [
            "# Review request: {$project->name}",
            "## What the owner wants help with\n\n".trim($question),
        ];

        $context = $this->readProjectContext->current($project);
        $notes = NotesDocument::parse($context->project ?? '');

        array_push($sections, ...($featureRequest === null
            ? $this->app($project, $notes, $context->capabilities)
            : $this->change($featureRequest, $notes, $context->capabilities)));

        $sections[] = $this->code($revision, $featureRequest !== null && $featureRequest->commit_sha === null && filled($featureRequest->patch));
        $sections[] = <<<'TEXT'
        ## What we want from you

        Your judgment: what you would worry about, and what you would do. Answer below. Guidance the owner keeps is added to the app's notes, and every later change follows it, so a general rule ("keep every payment in one place") is worth more than a one-off fix.
        TEXT;

        return implode("\n\n", array_filter($sections))."\n";
    }

    /**
     * What a developer needs to judge the whole app: what it is for, its
     * rules and decisions, its areas and how well they are tested, and what
     * changed lately.
     *
     * @param  array<string, Capability>  $capabilities
     * @return list<string|null>
     */
    protected function app(Project $project, NotesDocument $notes, array $capabilities): array
    {
        $observation = TestObservation::latestFor($project);

        return [
            $this->about($notes),
            ...$this->rules($notes),
            $this->areas($capabilities, $observation),
            $this->services($project),
            "## Tests\n\n".($observation === null ? 'The app\'s tests have not been run with coverage yet.' : trans_choice('{1} The app has 1 test.|[2,*] The app has :count tests.', $observation->testCount())),
            $this->recent($project, null, $capabilities),
        ];
    }

    /**
     * The rules, decisions and guidance every change in the app follows.
     *
     * @return list<string|null>
     */
    protected function rules(NotesDocument $notes): array
    {
        return [
            $this->items('Important rules', $notes->items('Rules')),
            $this->items('Decisions the owner made', $notes->items(RecordDecision::SECTION)),
            $this->items('Guidance the app already follows', $notes->items(UpdateProjectNotes::GUIDANCE_SECTION)),
        ];
    }

    /**
     * List the app's areas: what each is for, its code, how well it is
     * tested and its rules.
     *
     * @param  array<string, Capability>  $capabilities
     */
    protected function areas(array $capabilities, ?TestObservation $observation): ?string
    {
        $map = $observation?->map();

        $areas = array_map(function (Capability $capability) use ($map) {
            $tests = $map === null ? null : count($map->testsForArea($capability));
            $line = "### {$capability->name}\n\n".($capability->summary ?? '');
            $line .= "\n\nCode: ".($capability->paths === [] ? '(none named)' : implode(', ', array_map(fn (string $path) => "`{$path}`", $capability->paths)));
            $line .= "\n\n".match ($tests) {
                null => 'Not measured: the tests have not been run with coverage yet.',
                0 => 'No test runs this code.',
                default => trans_choice('{1} 1 test runs this code.|[2,*] :count tests run this code.', $tests),
            };

            return $line.($capability->rules() === [] ? '' : "\n\n".$this->items('#### Its rules', $capability->rules(), 4));
        }, array_values($capabilities));

        return $areas === [] ? null : "## Areas of the app\n\n".implode("\n\n", $areas);
    }

    /**
     * What a developer needs to judge one change: what was asked and how it
     * was understood, what must stay true, the notes of the areas it
     * touches, what the checks showed and did not show, and the code.
     *
     * @param  array<string, Capability>  $capabilities
     * @return list<string|null>
     */
    protected function change(FeatureRequest $featureRequest, NotesDocument $notes, array $capabilities): array
    {
        $run = $featureRequest->latestRun;
        $plan = $run->plan ?? null;
        $targets = $run->context['targets'] ?? $plan['capabilities'] ?? [];
        $proof = collect($this->describeProof->handle($featureRequest));

        return [
            $this->about($notes),
            "## The change the owner asked for\n\n".$featureRequest->instructions()."\n\nAsked on ".$featureRequest->created_at?->toFormattedDayDateString().'. '.$this->status($featureRequest),
            filled($plan['current_behavior'] ?? null) ? "## What it does now\n\n{$plan['current_behavior']}" : null,
            filled($plan['summary'] ?? null) ? "## How it was understood\n\n{$plan['summary']}".$this->items('', $plan['tasks'], 0) : null,
            $this->items('What must stay as it is', array_column($plan['preserve'] ?? [], 'statement')),
            $this->items('What it should do when done', $plan['acceptance_criteria'] ?? []),
            $this->items('Assumptions made without asking the owner', array_column($plan['assumptions'] ?? [], 'text')),
            $this->items('Questions the owner answered', array_map(fn (array $answer) => "{$answer['question']} {$answer['answer']}", $run->answers ?? [])),
            ...$this->rules($notes),
            // A change that stopped before it was understood names no
            // areas, so the developer gets the whole app to find their way.
            $targets === []
                ? $this->areas($capabilities, TestObservation::latestFor($featureRequest->project))
                : $this->items('Areas it touches', array_values(array_filter(array_map(fn (string $key) => $capabilities[$key]->name ?? null, $targets)))),
            filled($run?->context['text'] ?? null) ? "## Notes on those areas\n\n".$this->demote((string) $run->context['text']) : null,
            $this->items('What the checks showed', $proof->where('kind', '!=', 'gap')->pluck('text')->values()->all()),
            $this->items('What nothing checks yet', $proof->where('kind', 'gap')->pluck('text')->values()->all()),
            $this->problems($run),
            $this->patch($featureRequest),
            $this->recent($featureRequest->project, $featureRequest, array_intersect_key($capabilities, array_flip($targets))),
        ];
    }

    /**
     * Say what the app is for, from the notes' introduction and goal.
     */
    protected function about(NotesDocument $notes): ?string
    {
        $text = trim($notes->introduction."\n\n".($notes->section(UpdateProjectNotes::GOAL_SECTION) ?? ''));

        return $text === '' ? null : "## What the app is for\n\n{$this->demote($text)}";
    }

    /**
     * Say where the change stands, in plain words.
     */
    protected function status(FeatureRequest $featureRequest): string
    {
        return match (true) {
            $featureRequest->reverted_at !== null => 'The owner kept it, then undid it.',
            $featureRequest->accepted_at !== null => 'The owner kept it on '.$featureRequest->accepted_at->toFormattedDayDateString().'.',
            default => match ($featureRequest->status->value) {
                'generated' => 'It is made and waits for the owner to keep it.',
                'failed', 'cancelled' => 'It stopped before it was finished.',
                default => 'It is still being made.',
            },
        };
    }

    /**
     * The problems the last checks or the second look left open.
     */
    protected function problems(?Run $run): ?string
    {
        $problems = [
            ...($run?->feedback['details'] ?? []),
            ...array_map(fn (array $finding) => ucfirst($finding['severity']).': '.$finding['summary'].($finding['file'] ? " (`{$finding['file']}`)" : ''), $run?->review['findings'] ?? []),
        ];

        return $this->items('Problems still open', array_values(array_unique($problems)));
    }

    /**
     * The change's code, up to a size a page can hold.
     */
    protected function patch(FeatureRequest $featureRequest): ?string
    {
        if (blank($featureRequest->patch)) {
            return null;
        }

        preg_match_all('/^diff --git a\/(\S+)/m', $featureRequest->patch, $files);
        $limit = (int) config('builder.developer_reviews.max_patch_kb') * 1024;
        $diff = strlen($featureRequest->patch) > $limit
            ? Str::limit($featureRequest->patch, $limit, '')."\n… (cut here: the rest is in the code download)"
            : $featureRequest->patch;

        return $this->items('Files it changes', array_map(fn (string $file) => "`{$file}`", array_values(array_unique($files[1]))))
            ."\n\n## The change's code\n\n```diff\n".rtrim($diff)."\n```";
    }

    /**
     * The changes the owner kept lately, newest first: for a change, those
     * in the same areas; for the app, the last few.
     *
     * @param  array<string, Capability>  $capabilities  The areas to match
     */
    protected function recent(Project $project, ?FeatureRequest $featureRequest, array $capabilities): ?string
    {
        $limit = (int) config('builder.developer_reviews.recent_changes');
        $areas = array_keys($capabilities);

        /** @var Collection<int, FeatureRequest> $kept */
        $kept = $project->featureRequests()
            ->whereNotNull('accepted_at')
            ->whereNull('reverted_at')
            ->when($featureRequest !== null, fn ($query) => $query->whereKeyNot($featureRequest->getKey()))
            ->with('latestRun')
            ->latest('accepted_at')
            ->limit($featureRequest === null ? $limit : $limit * 6)
            ->get();

        $lines = $kept
            ->when($featureRequest !== null, fn (Collection $changes) => $changes->filter(
                fn (FeatureRequest $change) => array_intersect($areas, array_keys($change->latestRun->review['classification']['requested'] ?? [])) !== [],
            ))
            ->take($limit)
            ->map(fn (FeatureRequest $change) => $change->accepted_at?->toFormattedDayDateString().': '.Str::limit(Str::squish($change->summary ?: $change->prompt), 160).($change->commit_sha ? ' (`'.substr($change->commit_sha, 0, 7).'`)' : ''))
            ->values()
            ->all();

        return $this->items($featureRequest === null ? 'Recent changes' : 'Recent changes in the same areas', $lines);
    }

    /**
     * Name the outside services the app is connected to; never their keys.
     */
    protected function services(Project $project): ?string
    {
        return $this->items('Outside services', array_map(fn (string $service) => config("builder.services.{$service}.name").' ('.config("builder.services.{$service}.provider").')', $project->connectedServices()));
    }

    /**
     * Say how to get the code the review is about, and whether the change
     * is in it yet.
     */
    protected function code(?string $revision, bool $changeApart): string
    {
        if ($revision === null) {
            return "## The code\n\nThe app has no code yet.";
        }

        return "## The code\n\nDownload it from the question's page. It is the app as of commit `".substr($revision, 0, 12).'`.'
            .($changeApart ? ' The change is not in it yet: download it too, and apply it with `git apply`.' : '');
    }

    /**
     * A section that lists items, or nothing when there are none. Without
     * a heading only the list is returned. At most "limit" items are listed
     * when it is above zero.
     *
     * @param  array<mixed>  $items
     */
    protected function items(string $heading, array $items, int $limit = 0): ?string
    {
        $items = array_values(array_filter(array_map(trim(...), array_filter($items, is_string(...)))));

        if ($items === []) {
            return $heading === '' ? '' : null;
        }

        $more = $limit > 0 && count($items) > $limit ? count($items) - $limit : 0;
        $list = '- '.implode("\n- ", $limit > 0 ? array_slice($items, 0, $limit) : $items).($more > 0 ? "\n- and {$more} more" : '');

        return $heading === '' ? "\n\n{$list}" : (str_starts_with($heading, '#') ? $heading : "## {$heading}")."\n\n{$list}";
    }

    /**
     * Move the headings of notes text below this document's own, so the
     * notes read as part of a section.
     */
    protected function demote(string $markdown): string
    {
        return (string) preg_replace('/^(#{1,4})\s/m', '$1## ', $markdown);
    }
}
