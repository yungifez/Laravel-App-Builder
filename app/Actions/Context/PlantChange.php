<?php

namespace App\Actions\Context;

use App\Context\ProjectContext;
use App\Features\PatchSummary;

/**
 * Plant an unrelated edit in a change and see whether the review would
 * catch it, with the project's Effects and without them (architecture
 * §26.7, hypothesis C). Nothing is written: the plant is added to the
 * saved diff and only classified.
 */
class PlantChange
{
    public function __construct(private ClassifyChange $classifyChange) {}

    /**
     * Plant one file in every area the change was not about, then classify
     * the change with and without Effects. Each area gets the first of the
     * known paths, in sorted order, that it claims and the change's areas
     * do not, so the same inputs always give the same plants.
     *
     * @param  list<string>  $targets  The areas the change is about
     * @param  list<string>  $knownPaths  Project files seen in kept changes
     * @return list<array{area: string, path: string, with_effects: string, without_effects: string}>
     */
    public function handle(ProjectContext $context, array $targets, string $patch, array $knownPaths): array
    {
        $targets = $context->known($targets);
        $plants = $this->choose($context, $targets, $patch, $knownPaths);

        if ($plants === []) {
            return [];
        }

        $planted = rtrim($patch, "\n")."\n".implode("\n", array_map($this->diff(...), array_keys($plants)))."\n";
        $withEffects = $this->classifyChange->handle($context, $targets, $planted)->toArray();
        $withoutEffects = $this->classifyChange->handle($this->withoutEffects($context), $targets, $planted)->toArray();

        return array_map(fn (string $path, string $area) => [
            'area' => $area,
            'path' => $path,
            'with_effects' => $this->place($withEffects, $path),
            'without_effects' => $this->place($withoutEffects, $path),
        ], array_keys($plants), $plants);
    }

    /**
     * Pick a file for each area outside the change.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $knownPaths
     * @return array<string, string> Area keyed by planted path
     */
    protected function choose(ProjectContext $context, array $targets, string $patch, array $knownPaths): array
    {
        $changed = array_column(PatchSummary::files($patch), 'path');
        $candidates = $knownPaths;

        // A pattern without wildcards names one file.
        foreach ($context->capabilities as $capability) {
            foreach ($capability->paths as $pattern) {
                if (strpbrk($pattern, '*?[{') === false) {
                    $candidates[] = $pattern;
                }
            }
        }

        $candidates = array_values(array_diff(array_unique($candidates), $changed));
        sort($candidates);

        $plants = [];
        $areas = array_diff(array_keys($context->capabilities), $targets);
        sort($areas);

        foreach ($areas as $area) {
            foreach ($candidates as $path) {
                $claimers = $context->claiming($path);

                if (in_array($area, $claimers, true) && array_intersect($claimers, $targets) === [] && ! isset($plants[$path])) {
                    $plants[$path] = $area;

                    break;
                }
            }
        }

        return $plants;
    }

    /**
     * The one-line edit planted in a file.
     */
    protected function diff(string $path): string
    {
        return "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n@@ -1 +1,2 @@\n+// planted\n";
    }

    /**
     * The same context with no area saying what else it may affect.
     */
    protected function withoutEffects(ProjectContext $context): ProjectContext
    {
        return ProjectContext::fromOutline(array_map(
            fn (array $capability) => ['effects' => []] + $capability,
            $context->outline(),
        ));
    }

    /**
     * Where the planted file landed. Unexpected wins when two areas claim it.
     *
     * @param  array<string, mixed>  $classification
     */
    protected function place(array $classification, string $path): string
    {
        foreach (['unexpected', 'may_also_affect'] as $place) {
            foreach ($classification[$place] as $paths) {
                if (in_array($path, $paths, true)) {
                    return $place;
                }
            }
        }

        return 'unclaimed';
    }
}
