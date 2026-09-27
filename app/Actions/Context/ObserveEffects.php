<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Effect;
use App\Context\ProjectContext;
use App\Enums\EffectStrength;
use App\Features\TestMap;
use App\Models\Project;
use App\Models\TestObservation;

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
     * Only what tests ran is known. An area no test reaches gets no Effect,
     * which means "unknown", never "unaffected".
     */
    public function handle(Project $project, ProjectContext $context): ProjectContext
    {
        $observation = $context->capabilities === [] ? null : TestObservation::latestFor($project);

        if ($observation === null) {
            return $context;
        }

        $map = $observation->map();
        $observed = $observation->created_at?->format('Y-m-d');
        $capabilities = [];

        foreach ($context->capabilities as $key => $capability) {
            $capabilities[$key] = $capability
                ->withEffects($this->effectsOf($capability, $context, $map, $observed))
                ->withReachedBy($this->testFilesRunning($capability, $map));
        }

        return new ProjectContext($context->project, $capabilities, $context->problems);
    }

    /**
     * Get the test files whose tests ran the area's code, most tests first.
     *
     * @return list<string>
     */
    protected function testFilesRunning(Capability $capability, TestMap $map): array
    {
        $code = array_values(array_filter(array_keys($map->files), fn (string $path) => ! Capability::runBySuite($path) && $capability->claims($path)));
        $counts = [];

        foreach ($map->testsRunning($code) as $test) {
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
     * @return list<Effect>
     */
    protected function effectsOf(Capability $capability, ProjectContext $context, TestMap $map, ?string $observed): array
    {
        /** @var array<string, array{tests: array<int, true>, files: array<string, true>}> $reached */
        $reached = [];

        foreach ($map->files as $path => $tests) {
            if (Capability::runBySuite($path) || ! $capability->claims($path)) {
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
