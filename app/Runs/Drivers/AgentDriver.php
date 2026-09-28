<?php

namespace App\Runs\Drivers;

use App\Actions\Runs\RecordModelUsage;
use App\Actions\Runs\WriteBrief;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\ModelRole;
use App\Features\AcceptanceSelector;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\ProvidersUnavailable;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Plans and reviews changes with models: a planner writes the plan and an
 * independent reviewer judges the verified result, each on its own provider
 * and model (config/builder.php "models"). How the plan is carried out is
 * left to the driver that extends this one.
 */
abstract class AgentDriver implements ConstructionDriver
{
    /**
     * How many times the planner is asked for a plan that fits the format.
     */
    protected const PLAN_ATTEMPTS = 2;

    public function __construct(
        protected AcceptanceSelector $acceptanceSelector,
        protected RecordModelUsage $recordModelUsage,
    ) {}

    /**
     * Plan the change. A plan that does not fit the format is asked for once
     * more, with what was wrong, before the run gives up: a malformed answer
     * is usually a slip, not a sign the request cannot be planned.
     */
    public function plan(Run $run, PlanningContext $context): Plan
    {
        $selection = $this->acceptanceSelector->for($run->featureRequest);
        $prompt = $this->planningPrompt($context);

        for ($attempt = 1; ; $attempt++) {
            $response = $this->ask(fn () => FeaturePlanner::make()->prompt($prompt, $this->pictures($run->featureRequest), provider: ModelRole::Planner->providers()));

            $this->recordModelUsage->handle($run, ModelRole::Planner, $response);

            try {
                return Plan::fromModelOutput($this->structured($response, 'planner'), $selection['acceptance'], $selection['solution_key']);
            } catch (ConstructionFailed $exception) {
                if ($attempt >= self::PLAN_ATTEMPTS) {
                    throw $exception;
                }

                $run->recordEvent('plan_rejected', ['attempt' => $attempt, 'error' => $exception->getMessage()]);
                $prompt = $this->planningPrompt($context)."\n\n## Your previous plan was rejected\n\n{$exception->getMessage()}\nReturn a complete plan that fixes this.";
            }
        }
    }

    public function review(Run $run, ReviewEvidence $evidence): Review
    {
        return $this->reviewWith($run, $evidence, ModelRole::Reviewer->providers());
    }

    /**
     * Have the reviewer judge the change on the first of the given providers
     * that can serve it (the AI SDK fails over on provider trouble, such as
     * an account out of credit). A review on a later provider is logged.
     *
     * @param  array<string, string|null>  $providers  Provider names and their models, in order
     */
    protected function reviewWith(Run $run, ReviewEvidence $evidence, array $providers): Review
    {
        $response = $this->ask(fn () => ChangeReviewer::make()->prompt($this->reviewPrompt($evidence), $this->pictures($run->featureRequest), provider: $providers));

        $this->recordModelUsage->handle($run, ModelRole::Reviewer, $response);

        $wanted = array_key_first($providers);

        if ($response->meta->provider !== null && $response->meta->provider !== $wanted) {
            $run->recordEvent('reviewer_failed_over', ['wanted' => $wanted, 'used' => $response->meta->provider]);
        }

        return Review::fromModelOutput($this->structured($response, 'reviewer'));
    }

    /**
     * Ask an agent. When every AI service turns the request away, the run
     * stops and tells the owner why, instead of being retried as if it had
     * crashed.
     *
     * @param  callable(): AgentResponse  $prompt
     *
     * @throws ProvidersUnavailable
     */
    protected function ask(callable $prompt): AgentResponse
    {
        try {
            return $prompt();
        } catch (FailoverableException $exception) {
            throw ProvidersUnavailable::because($exception);
        }
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

        if ($context->parentRequest !== null && $context->parentAnswered) {
            $sections[] = "## This follows an earlier question\n\nEarlier question: {$context->parentRequest}\n\nThe answer given: {$context->parentSummary}";
        } elseif ($context->parentRequest !== null) {
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

        if ($context->routes !== []) {
            $sections[] = "## Addresses in the app\n\nEach address and the code that handles it.\n\n".implode("\n", array_map(fn (string $route) => "- {$route}", $context->routes));
        }

        if ($context->answers !== []) {
            $sections[] = "## The owner's answers\n\nThe owner settled these for this request. Plan with them and do not ask about them again.\n\n".implode("\n", array_map(
                fn (array $answer) => $answer['decided_by'] === 'owner'
                    ? "- {$answer['question']} {$answer['answer']}"
                    : "- {$answer['question']} The owner left this to you; use: {$answer['answer']}",
                $context->answers,
            ));
        }

        if (! $context->mayAsk) {
            $sections[] = "## Questions\n\nDo not ask the owner anything more for this request: return question as null and build on your recommendation.";
        }

        $sections[] = WriteBrief::compatibility($context->keepOldWorking);

        if ($context->services !== []) {
            $sections[] = WriteBrief::services($context->services);
        }

        $sections[] = "## Project files\n\nEach line is a folder, then the files in it.\n\n".self::byFolder($context->files);

        foreach ($context->contents as $path => $contents) {
            $sections[] = "## {$path}\n\n```\n{$contents}\n```";
        }

        return implode("\n\n", $sections);
    }

    /**
     * List files by folder, so each folder is named once. It says the same
     * as one path per line in about half the words.
     *
     * @param  list<string>  $files
     */
    public static function byFolder(array $files): string
    {
        $folders = [];

        foreach ($files as $file) {
            $folders[Str::contains($file, '/') ? Str::beforeLast($file, '/').'/' : ''][] = Str::afterLast($file, '/');
        }

        return implode("\n", array_map(
            fn (string $folder, array $names) => ($folder === '' ? '' : "{$folder}: ").implode(', ', $names),
            array_keys($folders),
            $folders,
        ));
    }

    /**
     * Get the pictures the owner attached, for the planner to see what they
     * mean and the reviewer to check the change against.
     *
     * @return list<StoredImage>
     */
    protected function pictures(FeatureRequest $featureRequest): array
    {
        return array_map(
            fn (array $image) => Image::fromStorage($image['path'], Config::string('builder.construction.images.disk')),
            $featureRequest->images ?? [],
        );
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

        // Observed by running the tests: evidence of reach, not a full list.
        if ($classification->observed !== null) {
            $reached = array_map(fn (string $area, int $tests) => ($evidence->areaNames[$area] ?? $area)." ({$area}, {$tests})", array_keys($classification->observed['areas']), $classification->observed['areas']);
            $lines[] = "- Tests that ran the changed code: {$classification->observed['tests']}".($reached === [] ? '' : '; they belong to '.implode(', ', $reached));

            if (($classification->observed['foundation'] ?? []) !== []) {
                $lines[] = '- Changed shared code that most of the tests run, so it can reach the whole app: '.implode(', ', $classification->observed['foundation']);
            }

            if ($classification->observed['unmapped'] !== []) {
                $lines[] = '- Changed PHP files no test ran, so their reach is unknown: '.implode(', ', $classification->observed['unmapped']);
            }
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
