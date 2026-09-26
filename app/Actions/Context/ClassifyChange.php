<?php

namespace App\Actions\Context;

use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Features\PatchSummary;

class ClassifyChange
{
    /**
     * Sort a change's files by area. A file claimed by a requested area is
     * requested, whatever else claims it. Otherwise each area claiming it is
     * "may also affect" when a requested area's Effect names it, and
     * unexpected when none does.
     *
     * @param  list<string>  $targets  The areas the change is about
     */
    public function handle(ProjectContext $context, array $targets, ?string $patch): ChangeClassification
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
        $contextUpdates = [];

        foreach (PatchSummary::files($patch) as $file) {
            $path = $file['path'];

            if (str_starts_with($path, '.builder/')) {
                $contextUpdates[] = $path;

                continue;
            }

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

        return new ChangeClassification($requested, $mayAlsoAffect, $unexpected, $unclaimed, $contextUpdates, $targets);
    }
}
