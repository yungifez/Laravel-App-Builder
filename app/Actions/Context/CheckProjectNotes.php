<?php

namespace App\Actions\Context;

use App\Context\ProjectContext;
use App\Features\TestMap;
use App\Features\UnsafeCode;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\TestObservation;
use App\Projects\Frontend;
use App\Projects\ProjectRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class CheckProjectNotes
{
    public function __construct(private ProjectRepository $repository, private ReadProjectContext $readProjectContext) {}

    /**
     * Compare the notes of the line the owner works in with the code at the
     * tip of its branch and list the obvious problems, in the owner's words. No model is involved: every finding
     * is a fact about the files, with the files it is about as its details.
     *
     * A finding the notes alone can put right also says how: "fix" names
     * the part of the notes and the items to take out of it. One only a
     * change to the app can put right says what to ask for ("ask"), so no
     * finding is a dead end.
     *
     * @return list<array{title: string, details: list<string>, fix?: array{part: string, remove: list<string>}, confirm?: array{part: string}, ask?: string}>
     */
    public function handle(Project $project): array
    {
        $head = $this->repository->head($project);
        $files = $this->repository->files($project, $head);
        $context = $this->readProjectContext->current($project);
        $findings = [];
        $secrets = UnsafeCode::secretFiles($files);
        $untested = [];

        // The one safety fact a file list proves on its own, so it is said first.
        if ($secrets !== []) {
            $findings[] = ['title' => __('Secret settings are saved in the app\'s code, where anyone with the code can read them.'), 'details' => $secrets, 'ask' => __('Move the secret settings out of my app\'s code and into its settings.')];
        }

        if ($context->problems !== []) {
            $findings[] = ['title' => __('Some notes could not be read, so I cannot use them.'), 'details' => $context->problems, 'ask' => __('Fix the notes about my app that could not be read.')];
        }

        if ($context->project === null) {
            $findings[] = ['title' => __('There is no description of what your app is for yet.'), 'details' => [], 'ask' => __('Write down in the notes what my app is for.')];
        }

        foreach ($context->capabilities as $capability) {
            $missing = array_values(array_filter($capability->paths, fn (string $pattern) => ! $this->matchesAny($pattern, $files)));

            if ($missing !== []) {
                $findings[] = ['title' => __('The notes on ":name" point to files that are not in the app.', ['name' => $capability->name]), 'details' => $missing, 'fix' => ['part' => "paths:{$capability->key}", 'remove' => $missing]];
            }

            $unknown = array_values(array_filter(array_map(fn ($effect) => $effect->to, $capability->effects), fn (string $key) => ! isset($context->capabilities[$key])));

            if ($unknown !== []) {
                $findings[] = ['title' => __('":name" says it is connected to something the notes do not describe.', ['name' => $capability->name]), 'details' => $unknown, 'fix' => ['part' => "effects:{$capability->key}", 'remove' => $unknown]];
            }

            if ($capability->testFiles === []) {
                $findings[] = ['title' => __('Nothing checks ":name" automatically.', ['name' => $capability->name]), 'details' => [__('No test for it runs with the checks.')], 'ask' => __('Add tests that check :name works as described', ['name' => $capability->name])];
                $untested[$capability->key] = true;
            }
        }

        $observation = TestObservation::latestFor($project);
        $unlisted = $this->unlistedBehaviors($context, $observation?->map());

        // Putting it right adds to the notes rather than taking out, so no
        // fix button: the title says where the owner writes it instead.
        if ($unlisted !== []) {
            $findings[] = ['title' => __('Your app\'s tests check things the notes do not describe. Copy each one into the rules of the part it belongs to, below.'), 'details' => $unlisted];
        }

        // A part already said to have no tests is not listed again.
        foreach ($this->unprovenBehaviors($project, $context, $observation) as $key => [$name, $behaviors]) {
            if (! isset($untested[$key])) {
                $findings[] = ['title' => __('":name" says it does things no test checks any more.', ['name' => $name]), 'details' => array_column($behaviors, 'name'), 'fix' => ['part' => "behaviors:{$key}", 'remove' => array_column($behaviors, 'key')]];
            }
        }

        // The app's own screens are described too, wherever its frontend keeps them.
        $described = array_values(array_filter([...Config::array('builder.context.described_paths'), ...Frontend::of($this->repository, $project, $head)->pages], is_string(...)));
        $undescribed = array_values(array_filter(Config::array('builder.context.undescribed'), is_string(...)));

        $unclaimed = array_values(array_filter($files, fn (string $path) => Str::startsWith($path, $described)
            && ! Str::is($undescribed, $path)
            && $context->claiming($path) === []));

        if ($unclaimed !== []) {
            $findings[] = ['title' => __('Some parts of the app are not described in any notes.'), 'details' => $unclaimed, 'ask' => __('Describe the parts of my app that the notes leave out.')];
        }

        // Rewriting is the fix, so no fix button: the owner reads the part
        // and corrects it, or says the notes are still right.
        foreach ($this->staleNotes($project, $context, $head, $files) as $key => [$name, $changed]) {
            $shown = array_slice($changed, 0, 10);

            if (count($changed) > count($shown)) {
                $shown[] = __('and :count more', ['count' => count($changed) - count($shown)]);
            }

            $findings[] = ['title' => __('The notes on ":name" were written before later changes to its code. Read them below and correct anything that changed.', ['name' => $name]), 'details' => $shown, 'confirm' => ['part' => "checked:{$key}"]];
        }

        return $findings;
    }

    /**
     * Get what the tests check for behaviours they name that no area's notes
     * list, in the tests' own words. A test names the behaviour it proves
     * with a `behavior:<key>` group; when the notes lose that key, the test
     * still proves it but nothing describes it. A rule that says what the
     * test checks describes it too, so copying the sentence into a part's
     * rules clears it.
     *
     * @return list<string>
     */
    protected function unlistedBehaviors(ProjectContext $context, ?TestMap $map): array
    {
        if ($map === null) {
            return [];
        }

        $listed = [];
        $rules = [];

        foreach ($context->capabilities as $capability) {
            foreach ($capability->behaviors as $behavior) {
                $listed[$behavior['key']] = true;
            }

            foreach ($capability->rules() as $rule) {
                $rules[] = self::plain($rule);
            }
        }

        $sentences = [];

        foreach ($map->tests as $index => $test) {
            foreach ($test['groups'] as $group) {
                if (str_starts_with($group, TestMap::BEHAVIOR_GROUP) && ! isset($listed[Str::after($group, TestMap::BEHAVIOR_GROUP)])) {
                    $sentence = $map->sentence($index);

                    if (! Str::contains(implode("\n", $rules), self::plain($sentence))) {
                        $sentences[] = $sentence;
                    }

                    break;
                }
            }
        }

        $sentences = array_values(array_unique($sentences));
        sort($sentences);

        return $sentences;
    }

    /**
     * Get, by part key, the part's name and the behaviours its notes list
     * that no test proves in the latest look at the app's tests. A test
     * proves a behaviour with a `behavior:<key>` group; when it is deleted
     * or loses the group, the notes still say the app does it. Only tests
     * seen after the part's notes were last saved count: a behaviour the
     * owner just added may wait for the change that tests it.
     *
     * @return array<string, array{0: string, 1: list<array{key: string, name: string}>}>
     */
    protected function unprovenBehaviors(Project $project, ProjectContext $context, ?TestObservation $observation): array
    {
        if ($observation?->created_at === null) {
            return [];
        }

        $written = $this->savedAt($project, $context);
        $proven = $observation->map()->provenBehaviors();
        $unproven = [];

        foreach ($context->capabilities as $capability) {
            $at = $written->get((string) $capability->file);
            $behaviors = array_values(array_filter($capability->behaviors, fn (array $behavior) => ! in_array($behavior['key'], $proven, true)));

            if ($at !== null && $observation->created_at->greaterThan($at) && $behaviors !== []) {
                $unproven[$capability->key] = [$capability->name, $behaviors];
            }
        }

        return $unproven;
    }

    /**
     * Get when each part's notes were last saved, by notes file.
     *
     * @return Collection<string, Carbon>
     */
    protected function savedAt(Project $project, ProjectContext $context): Collection
    {
        return ProjectNote::query()
            ->where('project_id', $project->id)
            ->where('branch', $project->branch())
            ->whereIn('path', array_values(array_filter(array_map(fn ($capability) => $capability->file, $context->capabilities))))
            ->pluck('updated_at', 'path')
            ->filter();
    }

    /**
     * Compare sentences as people copy them: any case, spacing or full stop.
     */
    protected static function plain(string $text): string
    {
        return rtrim(Str::lower(Str::squish($text)), '.');
    }

    /**
     * Get, by part key, the part's name and the code files the notes claim that changed after
     * the part's notes were last written. Only files the part names in its
     * paths count, never its tests, and only once at least the configured
     * number changed: most parts' code moves a little after their notes,
     * and a finding on every part would teach the owner to skip them all.
     *
     * A kept change is committed before what it did to the notes is saved,
     * so a change that rewrote its notes is never counted against them.
     *
     * @param  list<string>  $files  The files at the tip of the branch
     * @return array<string, array{0: string, 1: list<string>}>
     */
    protected function staleNotes(Project $project, ProjectContext $context, string $head, array $files): array
    {
        $written = $this->savedAt($project, $context);

        if ($written->isEmpty()) {
            return [];
        }

        $changed = $this->repository->changedSince($project, $head, $written->min());
        $minimum = Config::integer('builder.context.stale_notes.min_files');
        $stale = [];

        foreach ($context->capabilities as $capability) {
            $at = $written->get((string) $capability->file);

            if ($at === null) {
                continue;
            }

            $since = array_keys(array_filter($changed, fn (int $time, string $path) => $time > $at->getTimestamp()
                && in_array($path, $files, true)
                && ! in_array($path, $capability->testFiles, true)
                && Str::is($capability->paths, $path), ARRAY_FILTER_USE_BOTH));

            if (count($since) >= $minimum) {
                sort($since);
                $stale[$capability->key] = [$capability->name, $since];
            }
        }

        return $stale;
    }

    /**
     * Determine if a path pattern matches any of the files.
     *
     * @param  list<string>  $files
     */
    protected function matchesAny(string $pattern, array $files): bool
    {
        foreach ($files as $file) {
            if (Str::is($pattern, $file)) {
                return true;
            }
        }

        return false;
    }
}
