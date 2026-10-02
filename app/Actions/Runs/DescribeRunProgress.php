<?php

namespace App\Actions\Runs;

use App\Context\ProjectNotes;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Run;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\RunnerAgent;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DescribeRunProgress
{
    /**
     * What each check does, in the owner's words.
     */
    protected const CHECKS = [
        'Tests' => 'Running your app\'s tests',
        'Static analysis' => 'Reading the code for mistakes',
        'PHP formatting' => 'Checking the code is tidy',
        'Frontend format and lint' => 'Checking the screens\' code is tidy',
        'TypeScript' => 'Reading the screens\' code for mistakes',
    ];

    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Say what the change is doing right now, in the owner's words, while
     * it is being made: which parts of the app it is reading or changing,
     * or that it is trying the change out. A change takes a minute or two,
     * and a spinner alone says nothing.
     *
     * @return array{text: string, changed: int}|null
     */
    public function handle(Run $run): ?array
    {
        if ($run->status === RunStatus::Queued) {
            return $this->waiting($run);
        }

        if ($run->status === RunStatus::Planning) {
            return $this->planning($run);
        }

        if ($run->status === RunStatus::Verifying) {
            return $this->checking($run);
        }

        if ($run->status === RunStatus::Reviewing) {
            return $this->reviewing($run);
        }

        if ($run->driver === 'worker') {
            return $this->ownTool($run);
        }

        $progress = $this->live($run);

        return $progress === null ? null : $this->describe($run, $progress);
    }

    /**
     * Say what the owner's own Claude Code or Codex is doing. It works on
     * their computer, so what it does here is what we know: when it took
     * the change, which parts of the app its patch touches when it tries
     * it out on the app's server, and when it hands it back.
     *
     * @return array{text: string, changed: int}
     */
    protected function ownTool(Run $run): array
    {
        $since = (int) $run->events()->where('type', 'status')->where('data->to', RunStatus::Implementing->value)->max('sequence');
        $events = $run->events()
            ->whereIn('type', ['worker_query', 'worker_progress', 'worker_tried', 'worker_submitted'])
            ->where('sequence', '>', $since)
            ->reorder('sequence')
            ->get();
        $tried = $events->where('type', 'worker_tried')->last();
        $files = is_array($tried?->data['files'] ?? null) ? array_values(array_filter($tried->data['files'], is_string(...))) : [];
        $areas = $this->areas($run, $files);
        $last = $events->whereIn('type', ['worker_progress', 'worker_tried', 'worker_submitted'])->last();

        $text = match (true) {
            $events->isEmpty() => __('Waiting for your Claude Code or Codex to ask for it'),
            $last?->type === 'worker_submitted' => __('Your Claude Code or Codex handed it back. Getting it ready to check'),
            $last?->type === 'worker_tried' => __('Your Claude Code or Codex is trying it out'),
            $areas !== null => __('Your Claude Code or Codex is changing :areas', ['areas' => $areas]),
            $last !== null => __('Your Claude Code or Codex is changing your app'),
            default => __('Your Claude Code or Codex is reading how your app works'),
        };

        return ['text' => (string) $text, 'changed' => count($files)];
    }

    /**
     * Get what the coding agent last wrote about its progress, while it works.
     *
     * @return array{doing: string, last: string|null, read: list<string>, changed: list<string>, story: list<array{kind: string, text?: string, file?: string}>}|null
     */
    public function live(Run $run): ?array
    {
        // The owner's own tool tells its story through its calls, which
        // are kept as events, not through the workspace.
        if ($run->status !== RunStatus::Implementing || $run->workspace === null || $run->driver === 'worker') {
            return null;
        }

        // Several people may watch the same change; the workspace is read
        // at most once every few seconds.
        return Cache::remember("runs:{$run->id}:progress", now()->addSeconds(3), fn () => $this->read($run));
    }

    /**
     * Read what the coding agent wrote about its progress.
     *
     * @return array{doing: string, last: string|null, read: list<string>, changed: list<string>, story: list<array{kind: string, text?: string, file?: string}>}|null
     */
    protected function read(Run $run): ?array
    {
        $workspace = $run->workspace;
        $contents = rescue(
            fn () => $this->workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, RunnerAgent::TASK_DIRECTORY.'/progress.json'),
            null,
            report: false,
        );
        $data = is_string($contents) ? json_decode($contents, true) : null;

        if (! is_array($data) || ! is_string($data['doing'] ?? null)) {
            return null;
        }

        return [
            'doing' => $data['doing'],
            'last' => is_string($data['last'] ?? null) ? $data['last'] : null,
            'read' => array_values(array_filter(Arr::wrap($data['read'] ?? []), is_string(...))),
            'changed' => array_values(array_filter(Arr::wrap($data['changed'] ?? []), is_string(...))),
            'story' => AgentOutcome::story($data['story'] ?? []),
        ];
    }

    /**
     * @param  array{doing: string, last: string|null, read: list<string>, changed: list<string>, story: list<array{kind: string, text?: string, file?: string}>}  $progress
     * @return array{text: string, changed: int}
     */
    protected function describe(Run $run, array $progress): array
    {
        $notes = ProjectNotes::directory().'/';
        $changed = array_values(array_filter($progress['changed'], fn (string $path) => ! str_starts_with($path, $notes)));
        $last = $progress['last'];

        $changing = $this->areas($run, $changed);
        $reading = $this->areas($run, $last !== null ? [$last] : []);

        $text = match (true) {
            $progress['doing'] === 'testing' => __('Trying it out'),
            $last !== null && str_starts_with($last, $notes) => __('Writing down what I learned'),
            $progress['doing'] === 'changing' && $last !== null && str_starts_with($last, 'tests/') => __('Writing a test for it'),
            $progress['doing'] === 'changing' => $changing === null ? __('Changing your app') : __('Changing :areas', ['areas' => $changing]),
            default => $reading === null ? __('Reading how your app works') : __('Reading how :areas works', ['areas' => $reading]),
        };

        return ['text' => (string) $text, 'changed' => count($changed)];
    }

    /**
     * Say how many changes go before this one. Changes are made one at a
     * time, so a change can wait minutes behind another; "Getting started"
     * alone would look stuck.
     *
     * @return array{text: string, changed: int}|null
     */
    protected function waiting(Run $run): ?array
    {
        // A change the owner's own tool writes waits for that tool, not in
        // our line.
        $ahead = Run::query()
            ->where('id', '<', $run->id)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing, RunStatus::Reviewing])
            ->whereNot(fn ($query) => $query->where('driver', 'worker')->where('status', RunStatus::Implementing))
            ->count();

        return $ahead === 0 ? null : ['text' => trans_choice('Waiting its turn, 1 change ahead|Waiting its turn, :count changes ahead', $ahead), 'changed' => 0];
    }

    /**
     * Say which part of planning runs now. Planning first gets a copy of
     * the app, then reads how it is put together, then decides what to
     * change; each part leaves an event behind when it is done.
     *
     * @return array{text: string, changed: int}
     */
    protected function planning(Run $run): array
    {
        $last = $run->events()->whereIn('type', ['status', 'workspace_ready', 'compatibility'])->reorder('sequence', 'desc')->value('type');

        $text = match (true) {
            $last === 'compatibility' => __('Deciding what to change, and how to prove it works'),
            $last === 'workspace_ready', $run->workspace?->status === WorkspaceStatus::Ready => __('Reading how your app is put together'),
            default => __('Getting a copy of your app ready'),
        };

        return ['text' => (string) $text, 'changed' => 0];
    }

    /**
     * Say which check runs now, and how far the checks have come. Checking
     * takes minutes; the owner should see it move.
     *
     * @return array{text: string, changed: int}|null
     */
    protected function checking(Run $run): ?array
    {
        $verification = $run->verifications()->latest('id')->first();

        if ($verification === null || $verification->status !== VerificationStatus::Running) {
            return null;
        }

        $done = collect($verification->results ?? [])->countBy('stage');

        $text = self::checks((int) ($done['setup'] ?? 0) + (int) ($done['checks'] ?? 0)) ?? __('Trying it the way you asked for it');

        return ['text' => (string) $text, 'changed' => 0];
    }

    /**
     * Say which check runs once :done of the setup steps and checks, in
     * that order, have finished, or null once all have. Changes and
     * publishing run the same checks, so they say it the same way.
     */
    public static function checks(int $done): ?string
    {
        /** @var list<array{name: string}> $setup */
        $setup = config('builder.verification.setup', []);
        /** @var list<array{name: string}> $checks */
        $checks = config('builder.verification.checks', []);
        $checked = $done - count($setup);

        return match (true) {
            $checked < 0 => (string) __('Getting a fresh copy of your app ready to check'),
            $checked < count($checks) => (string) __(':check, check :number of :count', [
                'check' => isset(self::CHECKS[$checks[$checked]['name']]) ? __(self::CHECKS[$checks[$checked]['name']]) : __('Running “:name”', ['name' => $checks[$checked]['name']]),
                'number' => $checked + 1,
                'count' => count($checks),
            ]),
            default => null,
        };
    }

    /**
     * Say what the change is checked against while it is looked over: each
     * thing the owner asked for, and what must stay as it was.
     *
     * @return array{text: string, changed: int}|null
     */
    protected function reviewing(Run $run): ?array
    {
        $asked = count($run->plan['acceptance_criteria'] ?? []);
        $kept = count($run->plan['preserve'] ?? []);

        if ($asked === 0) {
            return null;
        }

        $text = trans_choice('Checking it against the one thing you asked for|Checking it against the :count things you asked for', $asked);

        if ($kept > 0) {
            $text = __(':asked, and :rules', ['asked' => $text, 'rules' => trans_choice('the one rule that must not change|the :count rules that must not change', $kept)]);
        }

        return ['text' => (string) $text, 'changed' => 0];
    }

    /**
     * Name the areas of the app the files belong to, from the notes, or
     * null when no area claims them.
     *
     * @param  list<string>  $paths
     */
    protected function areas(Run $run, array $paths): ?string
    {
        $names = [];

        foreach ($run->context['outline'] ?? [] as $area) {
            foreach ($paths as $path) {
                if (Str::is($area['paths'], $path)) {
                    $names[] = $area['name'];

                    break;
                }
            }
        }

        $names = array_slice(array_values(array_unique($names)), 0, 3);

        return $names === [] ? null : Arr::join($names, ', ', ' and ');
    }
}
