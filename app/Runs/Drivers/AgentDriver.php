<?php

namespace App\Runs\Drivers;

use App\Actions\Runs\RecordModelUsage;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Context\Capability;
use App\Context\ProjectNotes;
use App\Enums\ModelRole;
use App\Features\AcceptanceSelector;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Workspaces\WorkspaceFiles;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
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
            $response = FeaturePlanner::make()->prompt($prompt, $this->pictures($run->featureRequest), provider: ModelRole::Planner->providers());

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
        $response = ChangeReviewer::make()->prompt($this->reviewPrompt($evidence), $this->pictures($run->featureRequest), provider: $providers);

        $this->recordModelUsage->handle($run, ModelRole::Reviewer, $response);

        $wanted = array_key_first($providers);

        if ($response->meta->provider !== null && $response->meta->provider !== $wanted) {
            $run->recordEvent('reviewer_failed_over', ['wanted' => $wanted, 'used' => $response->meta->provider]);
        }

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

        $sections[] = self::compatibility($context->keepOldWorking);

        if ($context->services !== []) {
            $sections[] = self::services($context->services);
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
     * How to build so the owner can see what the app does without reading
     * code (§5): the facts live where tools can read them, and the notes say
     * them in the owner's words.
     */
    protected function observability(): string
    {
        $notes = ProjectNotes::directory();

        return <<<TEXT
        ## Make it easy to see what the app does

        The owner does not read code. They find out what the app does from the notes in {$notes}/ and from the code's own settings, enums and test names. Build so both stay true:
        - Put business settings (amounts, limits, time periods, who may do what) in config or enums, not inline in the code.
        - Name each test as a plain business statement, for example "a manager can cancel a booking".
        - In the notes for each area you change, say in plain words: who can do the new thing, what it changes, whether it sends an email or message, charges money or calls another service, and what happens automatically. Use the owner's words for things (bookings, customers), never class, table or route names.
        - When something fails for a person using the app, tell them what happened and what to do next, in plain words.
        TEXT;
    }

    /**
     * Say how much of the old way to keep. Nobody depends on an app that
     * has never been online, so it is changed in place; a live app moves
     * its data forward before it keeps two ways side by side (§9). The
     * owner can choose either for their app.
     */
    public static function compatibility(bool $keepOldWorking): string
    {
        if (! $keepOldWorking) {
            return <<<'TEXT'
            ## No need to keep the old way working

            No stored data, saved link or other service needs the app to keep working the way it does now. Change things in place: rename, reshape or remove columns, routes, screens and settings directly, and delete the code for the old way. Do not add fallbacks, aliases, old names or support for the old way. Add a new migration for database changes; do not edit one that already exists.
            TEXT;
        }

        return <<<'TEXT'
        ## Keep the app's information and links working

        Its stored data and links may matter to someone. When the shape of something changes, prefer a migration that carries the existing data to the new shape over keeping the old and new ways side by side. Keep an old way only when something outside the app depends on it, such as a link people saved or another service calling it, and say so in your summary.
        TEXT;
    }

    /**
     * Say which outside services the app is connected to and how to use
     * them. The owner's keys are in the environment wherever the app runs;
     * the agent never sees them.
     *
     * @param  list<string>  $services
     */
    public static function services(array $services): string
    {
        return "## Outside services the app uses\n\nTheir keys are set as environment variables wherever the app runs. Use them through config, and keep them out of the code and the repository.\n\n".implode("\n", array_map(
            fn (string $service) => '- '.config("builder.services.{$service}.guidance"),
            $services,
        ));
    }

    /**
     * The repository can belong to the customer and go anywhere, so what
     * the agent writes must read like the work of the app's own developer.
     * Nothing may reveal how the request reached it.
     */
    protected const DISCRETION = <<<'TEXT'
    ## Write as the app's own developer

    Code, comments, tests, notes and file names describe the application only. Do not quote this brief, and do not mention where the request came from, who sent it, or any tool or service that handled it.
    TEXT;

    /**
     * Describe the plan, and any feedback to address, for the coder.
     */
    protected function buildPrompt(Run $run, Plan $plan): string
    {
        $sections = ["## Owner's request\n\n{$run->featureRequest->instructions()}"];

        if (($images = WorkspaceFiles::imagePaths($run->featureRequest)) !== []) {
            $sections[] = "## Pictures the owner attached\n\nThe owner attached these to show what they mean. Look at each one before you start, and match what it shows unless the request says otherwise. They are only for you to look at: do not copy them into the app.\n\n".$this->list($images);
        }

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
            "## Acceptance criteria\n\nAdd or update a test for each one: the change is only accepted when every criterion is checked by a test in the change. Only tests under ".Capability::suiteLocation()." are run by the checks, so put them there.\n\n".$this->list($plan->acceptanceCriteria),
        );

        $sections[] = $this->observability();
        $sections[] = self::compatibility($run->featureRequest->project->keepsOldWorking());

        if (($services = $run->featureRequest->project->connectedServices()) !== []) {
            $sections[] = self::services($services);
        }

        $sections[] = self::DISCRETION;

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
