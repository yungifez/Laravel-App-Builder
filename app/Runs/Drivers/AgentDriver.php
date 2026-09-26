<?php

namespace App\Runs\Drivers;

use App\Actions\Runs\RecordModelUsage;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeatureCoder;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\ModelRole;
use App\Features\AcceptanceSelector;
use App\Models\Run;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Runs\ToolSession;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Builds changes with three model roles: a planner writes the plan, a coder
 * carries it out through the run's tools, and an independent reviewer judges
 * the verified result. Each role uses its own provider and model
 * (config/builder.php "models").
 */
class AgentDriver implements ConstructionDriver
{
    public function __construct(
        protected AcceptanceSelector $acceptanceSelector,
        protected RecordModelUsage $recordModelUsage,
    ) {}

    public function plan(Run $run, PlanningContext $context): Plan
    {
        $response = FeaturePlanner::make()->prompt(
            $this->planningPrompt($context),
            provider: ModelRole::Planner->provider(),
            model: ModelRole::Planner->model(),
        );

        $this->recordModelUsage->handle($run, ModelRole::Planner, $response);

        $selection = $this->acceptanceSelector->for($run->featureRequest);

        return Plan::fromModelOutput($this->structured($response, 'planner'), $selection['acceptance'], $selection['solution_key']);
    }

    public function build(Run $run, Plan $plan, ToolSession $tools): string
    {
        $response = FeatureCoder::make($tools, "coder:{$run->repairs}")->prompt(
            $this->buildPrompt($run, $plan)."\n\nThe workspace is at revision {$tools->revision()}.",
            provider: ModelRole::Coder->provider(),
            model: ModelRole::Coder->model(),
        );

        $this->recordModelUsage->handle($run, ModelRole::Coder, $response);

        $tools->throwIfHalted();

        return $response->text;
    }

    public function review(Run $run, ReviewEvidence $evidence): Review
    {
        return $this->reviewWith($run, $evidence, ModelRole::Reviewer->provider(), ModelRole::Reviewer->model());
    }

    /**
     * Have the reviewer judge the change on the given provider and model.
     */
    protected function reviewWith(Run $run, ReviewEvidence $evidence, string $provider, ?string $model): Review
    {
        $response = ChangeReviewer::make()->prompt(
            $this->reviewPrompt($evidence),
            provider: $provider,
            model: $model,
        );

        $this->recordModelUsage->handle($run, ModelRole::Reviewer, $response);

        return Review::fromModelOutput($this->structured($response, 'reviewer'));
    }

    public function canRepair(): bool
    {
        return true;
    }

    /**
     * Get a structured response's data, refusing plain text.
     *
     * @return array<string, mixed>
     *
     * @throws ConstructionFailed
     */
    protected function structured(AgentResponse $response, string $role): array
    {
        if (! $response instanceof StructuredAgentResponse) {
            throw new ConstructionFailed(__('The :role did not return structured output.', ['role' => $role]));
        }

        return $response->structured;
    }

    /**
     * Describe the request and the project for the planner.
     */
    protected function planningPrompt(PlanningContext $context): string
    {
        $sections = ["## Owner's request\n\n{$context->request}"];

        if ($context->parentRequest !== null) {
            $sections[] = "## This changes an earlier feature\n\nEarlier request: {$context->parentRequest}\n\nWhat was built: {$context->parentSummary}";
        }

        if ($context->targetStep !== null) {
            $sections[] = "## The owner selected this step to change\n\n".$this->json($context->targetStep);
        }

        if (filled($context->projectContext->project)) {
            $sections[] = "## Project notes\n\n{$context->projectContext->project}";
        }

        if ($context->projectContext->capabilities !== []) {
            $sections[] = "## Areas of the application\n\n".implode("\n", array_map(
                fn ($capability) => "- {$capability->key}: {$capability->name}".($capability->summary !== null ? ". {$capability->summary}" : ''),
                $context->projectContext->capabilities,
            ));
        }

        $sections[] = "## Project files\n\n".implode("\n", $context->files);

        foreach ($context->contents as $path => $contents) {
            $sections[] = "## {$path}\n\n```\n{$contents}\n```";
        }

        return implode("\n\n", $sections);
    }

    /**
     * Describe the plan, and any feedback to address, for the coder.
     */
    protected function buildPrompt(Run $run, Plan $plan): string
    {
        $sections = ["## Owner's request\n\n{$run->featureRequest->instructions()}"];

        if (filled($run->context['text'] ?? null)) {
            $sections[] = "## Project context\n\nWhat is known about the product for the areas this change touches.\n\n{$run->context['text']}";
        }

        if ($plan->currentBehavior !== null) {
            $sections[] = "## What it does now\n\n{$plan->currentBehavior}";
        }

        array_push(
            $sections,
            "## Plan\n\n{$plan->summary}",
            "## Tasks\n\n".$this->list($plan->tasks),
            "## Acceptance criteria\n\nAdd or update a test for each one: the change is only accepted when every criterion is checked by a test in the change. Only tests under ".implode(', ', (array) config('builder.verification.suite_paths'))." are run by the checks, so put them there.\n\n".$this->list($plan->acceptanceCriteria),
        );

        if ($plan->preserve !== []) {
            $sections[] = "## Keep as it is\n\nDo not change these. If the request cannot be done without changing one, stop and say so.\n\n".$this->list(array_column($plan->preserve, 'statement'));
        }

        if ($plan->assumptions !== []) {
            $sections[] = "## Assumptions\n\n".$this->list($plan->assumptions);
        }

        if ($run->feedback !== null) {
            $sections[] = "## Fix these problems with your earlier attempt\n\nThe files already contain your earlier changes.\n\n".$this->list($run->feedback['details']);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Lay out the evidence for the reviewer.
     */
    protected function reviewPrompt(ReviewEvidence $evidence): string
    {
        $results = array_map(
            fn (array $result) => "- [{$result['outcome']}] {$result['name']} ({$result['stage']})".($result['outcome'] === 'passed' ? '' : "\n  ".str_replace("\n", "\n  ", mb_substr($result['output'], -1500))),
            $evidence->verificationResults,
        );

        return implode("\n\n", array_filter([
            "## Owner's request\n\n{$evidence->request}",
            $evidence->projectContext !== '' ? "## Project context\n\n{$evidence->projectContext}" : null,
            $this->areasTouched($evidence),
            "## Plan\n\n{$evidence->plan->summary}",
            "## Acceptance criteria\n\n".$this->numbered($evidence->plan->acceptanceCriteria),
            $evidence->plan->preserve !== [] ? "## Must stay as it is\n\n".$this->list(array_column($evidence->plan->preserve, 'statement')) : null,
            "## Verification: {$evidence->verificationStatus}\n\n".implode("\n", $results),
            "## Tests deleted or weakened by the diff\n\n".($evidence->weakenedTests === [] ? 'None.' : $this->json($evidence->weakenedTests)),
            "## Diff\n\n```diff\n".$this->bounded($evidence->patch)."\n```",
        ]));
    }

    /**
     * Describe where the change landed by area, for the reviewer's
     * behaviour changes.
     */
    protected function areasTouched(ReviewEvidence $evidence): ?string
    {
        $classification = $evidence->classification;
        $lines = [];

        foreach (['requested' => 'requested', 'mayAlsoAffect' => 'may also affect', 'unexpected' => 'not expected'] as $property => $label) {
            foreach ($classification->{$property} as $area => $files) {
                $lines[] = "- {$area} ({$label}): ".($evidence->areaNames[$area] ?? $area).'; '.implode(', ', $files);
            }
        }

        foreach ($classification->targets as $area) {
            if (! isset($classification->requested[$area])) {
                $lines[] = "- {$area} (requested): ".($evidence->areaNames[$area] ?? $area).'; no files it claims changed';
            }
        }

        if ($classification->unclaimed !== []) {
            $lines[] = '- Files no area claims: '.implode(', ', $classification->unclaimed);
        }

        return $lines === [] ? null : "## Areas this change touched\n\nUse these area keys for your behaviour changes.\n\n".implode("\n", $lines);
    }

    /**
     * Cut a diff to the configured size for the reviewer, saying so when cut.
     */
    protected function bounded(string $patch): string
    {
        $limit = (int) config('builder.construction.limits.review_diff_characters');

        return mb_strlen($patch) > $limit
            ? mb_substr($patch, 0, $limit)."\n… (diff cut at {$limit} characters; judge the remainder as unreviewed)"
            : $patch;
    }

    /**
     * Format items as a Markdown list.
     *
     * @param  list<string>  $items
     */
    protected function list(array $items): string
    {
        return $items === [] ? '(none)' : '- '.implode("\n- ", $items);
    }

    /**
     * Format items as a numbered Markdown list, starting at 1.
     *
     * @param  list<string>  $items
     */
    protected function numbered(array $items): string
    {
        return $items === [] ? '(none)' : implode("\n", array_map(fn (int $index, string $item) => ($index + 1).". {$item}", array_keys($items), $items));
    }

    /**
     * Encode data for a prompt.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
