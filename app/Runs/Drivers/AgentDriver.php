<?php

namespace App\Runs\Drivers;

use App\Actions\Runs\RecordModelUsage;
use App\Actions\Runs\WriteBrief;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\ModelRole;
use App\Features\AcceptanceSelector;
use App\Features\AppBoundaries;
use App\Features\AppFaults;
use App\Features\AppTraces;
use App\Features\NewTests;
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
            fn (array $result) => match (true) {
                $result['outcome'] === 'passed' => "- [passed] {$result['name']} ({$result['stage']})",
                // It failed on the starting commit too: only what is new
                // there is the change's doing.
                ($result['at_start'] ?? null) === 'failed' => "- [failed before this change too] {$result['name']} ({$result['stage']})".(($result['new_problems'] ?? []) === [] ? '' : "\n  New with the change:\n  - ".implode("\n  - ", $result['new_problems'])),
                default => "- [{$result['outcome']}] {$result['name']} ({$result['stage']})\n  ".str_replace("\n", "\n  ", mb_substr($result['output'], -1500)),
            },
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
            $this->changeEvidence($evidence),
            "## Diff\n\n```diff\n".$this->bounded($evidence->patch)."\n```",
        ]));
    }

    /**
     * Describe what running the app with and without the change showed:
     * the new tests that pass without it, what it did to the addresses the
     * app answers, how far tests reach into its new code, what its code
     * saved and sent in the requests the tests made, and what those
     * requests left behind when one thing was made to fail. These are
     * measured facts; whether each was wanted is the reviewer's to judge
     * against the plan.
     */
    protected function changeEvidence(ReviewEvidence $evidence): ?string
    {
        $measured = $evidence->changeEvidence;
        $parts = [];

        if (isset($measured['new_tests'])) {
            $passing = array_values(array_filter($measured['new_tests'], fn (array $test) => $test['without_change'] === NewTests::PASSED));
            $parts[] = sprintf('The change added %d tests. Tests that fail without its code, as a test of new behaviour must: %d.', count($measured['new_tests']), count($measured['new_tests']) - count($passing))
                .($passing === [] ? '' : " These pass without it, so they do not check what it does:\n".$this->list(array_map(fn (array $test) => "{$test['file']}: {$test['name']}", $passing)));
        }

        $routes = $measured['routes'] ?? [];

        if (($routes['added'] ?? []) !== []) {
            $parts[] = "Routes it added, with their middleware:\n".$this->list(array_map(fn (array $route) => $route['route'].' ['.implode(', ', $route['middleware']).']', $routes['added']));
        }

        if (($routes['changed'] ?? []) !== []) {
            $parts[] = "Routes whose middleware it changed:\n".$this->list(array_map(fn (array $route) => $route['route'].($route['lost'] === [] ? '' : ' lost '.implode(', ', $route['lost'])).($route['gained'] === [] ? '' : ' gained '.implode(', ', $route['gained'])), $routes['changed']));
        }

        if (($routes['removed'] ?? []) !== []) {
            $parts[] = "Routes it removed:\n".$this->list($routes['removed']);
        }

        if (isset($measured['new_code'])) {
            $code = $measured['new_code'];
            $parts[] = sprintf('Of its %d new lines of PHP that can run, tests ran %d; %d of those only its own tests ran.', $code['lines'], $code['run'], $code['own_tests_only'])
                .($code['unrun'] === [] ? '' : " No test ran:\n".$this->list(array_map(fn (string $path, array $lines) => $path.': line '.implode(', ', $lines), array_keys($code['unrun']), $code['unrun'])));
        }

        if (isset($measured['traces'])) {
            $traces = $measured['traces'];
            $parts[] = sprintf('While the tests ran, %d requests to the app were recorded: their queries, transactions and what they sent. %d ran code the change added.', $traces['requests'], $traces['reached'])
                .match (true) {
                    $traces['findings'] !== [] => " Recorded from the code the change added:\n".$this->list(array_map($this->recorded(...), $traces['findings'])),
                    $traces['reached'] === 0 => ' So the recording says nothing about the change.',
                    default => ' The code the change added saved nothing on a GET request, kept nothing after refusing a request, and sent nothing while a transaction was open.',
                }
            .($traces['unseen'] === 0 ? '' : sprintf("\nOf the requests that ran the change's code, %d opened a transaction in a test that fakes mail, jobs or notifications, so what they sent, and when, was not seen.", $traces['unseen']));
        }

        if (isset($measured['boundaries'])) {
            $boundaries = $measured['boundaries'];
            $parts[] = 'The recording also says in which part of a request each thing ran: while Laravel checked who may act, checked the input, handled the request or built the response. Checks and responses can run many times per request and before the request is refused, so nothing in them may save, queue or send.'
                .($boundaries['findings'] === []
                    ? ' The code the change added saved and sent nothing in those parts.'
                    : " Saved, queued or sent by the code the change added in those parts:\n".$this->list(array_map($this->crossed(...), $boundaries['findings'])))
                .($boundaries['unknown'] === 0 ? '' : sprintf("\nFor %d recorded things the part of the request could not be told.", $boundaries['unknown']));
        }

        if (isset($measured['faults'])) {
            $faults = $measured['faults'];
            $parts[] = sprintf("One failure at a time was caused in requests that ran the change's code: an email that could not be sent, an outside call that got no answer, or a save the database refused. Of %d places where those requests send or save, %d were tried and the failure happened in %d.", $faults['points'], $faults['run'] + $faults['missed'], $faults['run'])
                .match (true) {
                    $faults['findings'] !== [] => " What the app left behind:\n".$this->list(array_map($this->left(...), $faults['findings'])),
                    $faults['run'] === 0 => ' So this says nothing about the change.',
                    default => ' Each time the app left nothing behind: it had saved nothing before a server error, sent nothing before a save it lost, and kept no part of a save it lost.',
                };
        }

        return $parts === [] ? null : "## What running the app with and without the change showed\n\n".implode("\n\n", $parts);
    }

    /**
     * Say one thing the app left behind when a failure was caused in the
     * change's code, for the reviewer.
     *
     * @param  array{kind: string, route: string, failed: string, what: string, at: string|null, test: string}  $finding
     */
    protected function left(array $finding): string
    {
        $did = match ($finding['kind']) {
            AppFaults::SAVED_THEN_FAILED => 'the request ended in a server error but had already saved',
            AppFaults::SENT_THEN_LOST => 'the save was lost but the request had already sent',
            AppFaults::SAVED_IN_PART => 'the save was lost but the request kept what it had saved before it, with no transaction around both',
            default => $finding['kind'],
        };

        return "{$finding['route']}: when {$finding['failed']} failed"
            .($finding['at'] === null ? '' : " at {$finding['at']}")
            .", {$did}: {$finding['what']} (caused in {$finding['test']})";
    }

    /**
     * Say one thing the recorder saw the change's code do, for the reviewer.
     *
     * @param  array{kind: string, route: string, what: string, at: string|null, test: string|null}  $finding
     */
    protected function recorded(array $finding): string
    {
        $did = match ($finding['kind']) {
            AppTraces::SAVED_ON_READ => 'saved data on a request that only reads',
            AppTraces::KEPT_AFTER_REFUSAL => 'refused the request but kept what it had saved',
            AppTraces::SENT_BEFORE_SAVED => 'sent this while a database transaction was still open, so it goes out even when the transaction is rolled back',
            default => $finding['kind'],
        };

        return "{$finding['route']} {$did}: {$finding['what']}"
            .($finding['at'] === null ? '' : " at {$finding['at']}")
            .($finding['test'] === null ? '' : " (seen in {$finding['test']})");
    }

    /**
     * Say one thing the change's code saved or sent in a part of a request
     * that must not change anything, for the reviewer.
     *
     * @param  array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}  $finding
     */
    protected function crossed(array $finding): string
    {
        $while = match ($finding['kind']) {
            AppBoundaries::CHANGED_WHILE_AUTHORIZING => 'while Laravel checked whether the person may act',
            AppBoundaries::CHANGED_WHILE_VALIDATING => 'while Laravel checked the input',
            AppBoundaries::CHANGED_WHILE_RENDERING => 'while Laravel built the response',
            default => $finding['kind'],
        };

        return "{$finding['route']} {$while}: {$finding['what']}"
            .($finding['at'] === null ? '' : " at {$finding['at']}")
            .($finding['in'] === null ? '' : " in {$finding['in']}")
            .($finding['test'] === null ? '' : " (seen in {$finding['test']})");
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
