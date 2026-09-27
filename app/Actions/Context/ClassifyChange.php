<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Features\PatchSummary;
use App\Features\TestMap;

class ClassifyChange
{
    /**
     * Sort a change's files by area. A file claimed by a requested area is
     * requested, whatever else claims it. Otherwise each area claiming it is
     * "may also affect" when a requested area's Effect names it, and
     * unexpected when none does.
     *
     * With a map of the project's tests, the change's observed impact is
     * added: the tests that ran the changed code, and the areas they belong
     * to (direction 22). Changed code no test ran is listed as unknown.
     * The tests are those that ran the changed lines, when the map knows
     * them, else the whole changed file. Changed code most tests run (the
     * foundation) is listed apart: its reach is the whole app, so it counts
     * toward no one area. A change to a few lines of a foundation file that
     * only some tests run is not broad.
     *
     * $mapIncludesChange says whether the map was made with the change in
     * place, so its line numbers are the new file's.
     *
     * @param  list<string>  $targets  The areas the change is about
     * @param  list<string>  $notes  The notes the change rewrote
     */
    public function handle(ProjectContext $context, array $targets, ?string $patch, array $notes = [], ?TestMap $map = null, bool $mapIncludesChange = true): ChangeClassification
    {
        $targets = $context->known($targets);
        $effectTargets = [];

        foreach ($targets as $target) {
            foreach ($context->capabilities[$target]->effects as $effect) {
                $effectTargets[$effect->to] = true;
            }
        }

        $requested = [];
        $mayAlsoAffect = [];
        $unexpected = [];
        $unclaimed = [];
        $contextUpdates = $notes;

        foreach (PatchSummary::files($patch) as $file) {
            $path = $file['path'];

            $claimers = $context->claiming($path);
            $requestedClaimers = array_values(array_intersect($claimers, $targets));

            if ($claimers === []) {
                $unclaimed[] = $path;
            } elseif ($requestedClaimers !== []) {
                foreach ($requestedClaimers as $key) {
                    $requested[$key][] = $path;
                }
            } else {
                foreach ($claimers as $key) {
                    if (isset($effectTargets[$key])) {
                        $mayAlsoAffect[$key][] = $path;
                    } else {
                        $unexpected[$key][] = $path;
                    }
                }
            }
        }

        return new ChangeClassification($requested, $mayAlsoAffect, $unexpected, $unclaimed, $contextUpdates, $targets, $this->observed($context, $patch, $map, $mapIncludesChange));
    }

    /**
     * Find the tests that ran the changed code, and the areas they belong to.
     *
     * @return array{areas: array<string, int>, tests: int, unmapped: list<string>, foundation: list<string>, by_line: list<string>}|null
     */
    protected function observed(ProjectContext $context, ?string $patch, ?TestMap $map, bool $mapIncludesChange): ?array
    {
        if ($map === null || $map->isEmpty()) {
            return null;
        }

        $code = array_column(array_filter(
            PatchSummary::files($patch),
            fn (array $file) => str_ends_with($file['path'], '.php') && ! Capability::runBySuite($file['path']),
        ), 'diff', 'path');
        $foundation = $map->foundation();
        $broad = [];
        $byLine = [];
        $ran = [];

        foreach ($code as $path => $diff) {
            $tests = $map->testsRunningLines($path, PatchSummary::changedLines($diff, $mapIncludesChange));

            if ($tests !== null) {
                $byLine[] = $path;
            }

            if ($tests !== null ? $map->reachesMost(count($tests)) : in_array($path, $foundation, true)) {
                $broad[] = $path;

                continue;
            }

            foreach ($tests ?? $map->testsRunning([$path]) as $test) {
                $ran[$test] = true;
            }
        }

        $tests = array_keys($ran);
        $areas = [];

        foreach ($tests as $test) {
            foreach ($map->areasOf($test, $context) as $area) {
                $areas[$area] = ($areas[$area] ?? 0) + 1;
            }
        }

        ksort($areas);

        return [
            'areas' => $areas,
            'tests' => count($tests),
            'unmapped' => array_values(array_filter(array_keys($code), fn (string $path) => ! isset($map->files[$path]))),
            'foundation' => $broad,
            'by_line' => $byLine,
        ];
    }
}
