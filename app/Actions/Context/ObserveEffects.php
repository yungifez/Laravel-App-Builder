<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\ChangeClassification;
use App\Context\Effect;
use App\Context\ProjectContext;
use App\Enums\EffectStrength;
use App\Features\TestMap;
use App\Models\Project;
use App\Models\Run;
use App\Models\TestObservation;
use Illuminate\Database\Eloquent\Builder;

class ObserveEffects
{
    /**
     * Code files named in an observed Effect's reason, at most.
     */
    protected const NAMED_FILES = 3;

    /**
     * Add the Effects the project's tests show to its context: when tests
     * that belong to area B ran code that area A claims, changing A may
     * warrant checking B (direction 22, §4). Two or more such tests make
     * the Effect strong; one makes it possible. They sit beside the Effects
     * written in the notes, each with its own source.
     *
     * Each area also learns which test files ran its code: the tests an
     * agent runs first while it works (§9). The whole suite still decides.
     *
     * Kept changes add evidence too (§6): when changes the owner kept were
     * about area A and also changed area B, A gets a "history" Effect on B.
     *
     * Foundation code, which most tests run, is left out of both: it would
     * tie every area to every other.
     *
     * Only what was seen is known. An area no test reaches and no change
     * moved with gets no Effect, which means "unknown", never "unaffected".
     */
    public function handle(Project $project, ProjectContext $context): ProjectContext
    {
        if ($context->capabilities === []) {
            return $context;
        }

        $observation = TestObservation::latestFor($project);
        $map = $observation?->map();
        $observed = $observation?->created_at?->format('Y-m-d');
        $foundation = $map?->foundation() ?? [];
        $history = $this->history($project, $context, $foundation);
        $capabilities = [];

        foreach ($context->capabilities as $key => $capability) {
            if ($map !== null) {
                $capability = $capability
                    ->withEffects($this->effectsOf($capability, $context, $map, $observed, $foundation))
                    ->withReachedBy($this->testFilesRunning($capability, $map));
            }

            $capabilities[$key] = $capability->withEffects($history[$key] ?? []);
        }

        return new ProjectContext($context->project, $capabilities, $context->problems);
    }

    /**
     * Get the Effects the kept changes show, per area: how many kept changes
     * about the area also changed each other area, when enough agree. A
     * change to foundation code alone does not tie two areas.
     *
     * @param  list<string>  $foundation
     * @return array<string, list<Effect>>
     */
    protected function history(Project $project, ProjectContext $context, array $foundation): array
    {
        $runs = Run::query()
            ->whereNotNull('review')
            ->whereHas('featureRequest', fn (Builder $query) => $query->where('project_id', $project->id)->whereNotNull('accepted_at')->whereNull('reverted_at'))
            ->with('featureRequest')
            ->latest('id')
            ->get()
            ->unique('feature_request_id')
            ->take((int) config('builder.context.history.window'));

        /** @var array<string, array<string, array{count: int, last: string|null}>> $together */
        $together = [];

        foreach ($runs as $run) {
            if (! isset($run->review['classification'])) {
                continue;
            }

            $classification = ChangeClassification::fromArray($run->review['classification']);
            $kept = $run->featureRequest->accepted_at?->format('Y-m-d');

            foreach (array_intersect($classification->targets, array_keys($context->capabilities)) as $from) {
                foreach (array_diff($this->touched($classification, $foundation), [$from]) as $to) {
                    if (! isset($context->capabilities[$to])) {
                        continue;
                    }

                    $seen = $together[$from][$to] ?? ['count' => 0, 'last' => null];
                    $together[$from][$to] = ['count' => $seen['count'] + 1, 'last' => max($seen['last'], $kept)];
                }
            }
        }

        $effects = [];

        foreach ($together as $from => $areas) {
            ksort($areas);

            foreach ($areas as $to => $evidence) {
                if ($evidence['count'] < (int) config('builder.context.history.min_changes')) {
                    continue;
                }

                $effects[$from][] = new Effect(
                    to: $to,
                    strength: EffectStrength::Historical,
                    reason: __(':count kept changes to this area also changed :area.', ['count' => $evidence['count'], 'area' => $context->capabilities[$to]->name]),
                    source: 'history',
                    observed: $evidence['last'],
                );
            }
        }

        return $effects;
    }

    /**
     * Get the areas a change touched through code other than the foundation.
     *
     * @param  list<string>  $foundation
     * @return list<string>
     */
    protected function touched(ChangeClassification $classification, array $foundation): array
    {
        $areas = [];

        foreach ([$classification->requested, $classification->mayAlsoAffect, $classification->unexpected] as $section) {
            foreach ($section as $area => $files) {
                if (array_diff($files, $foundation) !== []) {
                    $areas[$area] = true;
                }
            }
        }

        return array_keys($areas);
    }

    /**
     * Get the test files whose tests ran the area's code, most tests first.
     *
     * @return list<string>
     */
    protected function testFilesRunning(Capability $capability, TestMap $map): array
    {
        $counts = [];

        foreach ($map->testsForArea($capability) as $test) {
            $file = $map->tests[$test]['file'] ?? null;

            if ($file !== null && Capability::runBySuite($file)) {
                $counts[$file] = ($counts[$file] ?? 0) + 1;
            }
        }

        uksort($counts, fn (string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_keys($counts);
    }

    /**
     * Get the Effects observed for one area.
     *
     * @param  list<string>  $foundation
     * @return list<Effect>
     */
    protected function effectsOf(Capability $capability, ProjectContext $context, TestMap $map, ?string $observed, array $foundation): array
    {
        /** @var array<string, array{tests: array<int, true>, files: array<string, true>}> $reached */
        $reached = [];

        foreach ($map->files as $path => $tests) {
            if (Capability::runBySuite($path) || in_array($path, $foundation, true) || ! $capability->claims($path)) {
                continue;
            }

            foreach ($tests as $test) {
                foreach ($map->areasOf($test, $context) as $area) {
                    if ($area === $capability->key) {
                        continue;
                    }

                    $reached[$area]['tests'][$test] = true;
                    $reached[$area]['files'][$path] = true;
                }
            }
        }

        ksort($reached);
        $effects = [];

        foreach ($reached as $area => $evidence) {
            $tests = count($evidence['tests']);
            $files = array_keys($evidence['files']);
            sort($files);

            $effects[] = new Effect(
                to: $area,
                strength: $tests >= 2 ? EffectStrength::Strong : EffectStrength::Possible,
                reason: trans_choice(':count test for :area runs this area\'s code (:files).|:count tests for :area run this area\'s code (:files).', $tests, [
                    'area' => $context->capabilities[$area]->name,
                    'files' => implode(', ', array_slice($files, 0, self::NAMED_FILES)).(count($files) > self::NAMED_FILES ? ', …' : ''),
                ]),
                source: 'tests',
                observed: $observed,
            );
        }

        return $effects;
    }
}
