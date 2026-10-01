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
        if ($run->status === RunStatus::Planning) {
            return $this->planning($run);
        }

        if ($run->status === RunStatus::Verifying) {
            return $this->checking($run);
        }

        $progress = $this->live($run);

        return $progress === null ? null : $this->describe($run, $progress);
    }

    /**
     * Get what the coding agent last wrote about its progress, while it works.
     *
     * @return array{doing: string, last: string|null, read: list<string>, changed: list<string>, story: list<array{kind: string, text?: string, file?: string}>}|null
     */
    public function live(Run $run): ?array
    {
        if ($run->status !== RunStatus::Implementing || $run->workspace === null) {
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

        /** @var list<array{name: string}> $setup */
        $setup = config('builder.verification.setup', []);
        /** @var list<array{name: string}> $checks */
        $checks = config('builder.verification.checks', []);
        $done = collect($verification->results ?? [])->countBy('stage');
        $set = (int) ($done['setup'] ?? 0);
        $checked = (int) ($done['checks'] ?? 0);

        $text = match (true) {
            $set < count($setup) => __('Getting a fresh copy of your app ready to check'),
            $checked < count($checks) => __(':check, check :number of :count', [
                'check' => isset(self::CHECKS[$checks[$checked]['name']]) ? __(self::CHECKS[$checks[$checked]['name']]) : __('Running “:name”', ['name' => $checks[$checked]['name']]),
                'number' => $checked + 1,
                'count' => count($checks),
            ]),
            default => __('Trying it the way you asked for it'),
        };

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
