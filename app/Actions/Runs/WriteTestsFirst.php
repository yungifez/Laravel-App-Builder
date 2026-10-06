<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Ai\Agents\TestWriter;
use App\Context\Capability;
use App\Enums\ModelRole;
use App\Features\PatchSummary;
use App\Features\WrittenTests;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\AiAttempts;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\ProvidersUnavailable;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Support\Secrets;
use App\Workspaces\WorkspaceManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\StructuredAgentResponse;

class WriteTestsFirst
{
    public function __construct(
        protected RunWorkspaceCommand $runWorkspaceCommand,
        protected WorkspaceManager $workspaces,
        protected RecordModelUsage $recordModelUsage,
        protected GatherPlanningContext $gatherPlanningContext,
    ) {}

    /**
     * Have another model write the tests for each item the plan's tests
     * must check, before the change is built (§12). The coder cannot fit
     * them to its own code: they are written into the workspace before it
     * starts and put back after it finishes. Files that keep the rules in
     * WrittenTests are kept, and only the refused ones are asked for once
     * more. An item still without a kept test is left to the coder; when
     * none is kept, the change is built as before, with tests the coder
     * writes.
     */
    public function handle(Run $run, Plan $plan, Workspace $workspace, PlanningContext $context): Plan
    {
        $items = $plan->verifyItems();

        // A worker outside our boxes gets the written tests with its task;
        // a driver that does not build gets none.
        if (! config('builder.verification.written_first.enabled') || ! in_array($run->driver, ['sdk', 'worker'], true) || $items === []) {
            return $plan;
        }

        $existing = array_values(array_filter(explode("\0", $this->runWorkspaceCommand->handle(
            $workspace,
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', 'tests', 'composer.json'],
            120,
        )->output)));

        $prompt = $this->prompt($plan, $items, $context, $workspace, $existing);
        $ask = $prompt;
        $attempts = max(1, (int) config('builder.verification.written_first.attempts'));
        $kinds = array_column($items, 'kind');
        $kept = ['files' => [], 'tests' => []];

        for ($attempt = 1; ; $attempt++) {
            RunCancelled::throwIfCancelling($run);

            try {
                $response = app(AiAttempts::class)->for($run, fn () => TestWriter::make()->prompt($ask, provider: ModelRole::Reviewer->providers()));
            } catch (FailoverableException $exception) {
                throw ProvidersUnavailable::afterFailover($exception);
            } catch (RequestException $exception) {
                $stop = ProvidersUnavailable::fromResponse($exception);
                $run->recordEvent('ai_service_error', ['reason' => $stop->reason()->value, ...(array) $stop->serviceError()]);

                throw $stop;
            }

            $this->recordModelUsage->handle($run, ModelRole::Reviewer, $response);

            // The files kept from the last answer come first, so a file
            // or test given again never replaces one already kept.
            $sorted = $response instanceof StructuredAgentResponse
                ? WrittenTests::sort($this->withKept($kept, $response->structured), $kinds, fn (string $path) => in_array($path, $existing, true))
                : ['files' => $kept['files'], 'tests' => $kept['tests'], 'refused' => [], 'others' => [(string) __('Return the files and the tests as structured output.')], 'problems' => [(string) __('Return the files and the tests as structured output.')]];
            $kept = ['files' => $sorted['files'], 'tests' => $sorted['tests']];

            if ($sorted['problems'] !== [] && $attempt < $attempts) {
                $ask = $prompt."\n\n".$this->retry($sorted);

                continue;
            }

            if ($kept['tests'] === []) {
                $run->recordEvent('tests_not_written', ['error' => Str::limit(implode("\n", $sorted['problems']), 2000)]);

                return $plan;
            }

            // An item whose test was refused is left to the coder, who
            // writes its test as for any change, and the review holds it
            // to that test.
            $covered = array_column($kept['tests'], 'item');
            $dropped = array_values(array_filter(array_map(fn (array $item, int $index) => in_array($index + 1, $covered, true) ? null : ['item' => $index + 1, 'case' => $item['text']], $items, array_keys($items))));

            $run->recordEvent('tests_written', [
                'files' => array_keys($kept['files']),
                'tests' => count($kept['tests']),
                ...($dropped === [] ? [] : ['dropped' => $dropped, 'reasons' => array_map(fn (string $reason) => Str::limit($reason, 300), $sorted['problems'])]),
            ]);

            return $plan->withWrittenTests($kept['files'], $kept['tests']);
        }
    }

    /**
     * Put the files and tests kept so far before the writer's new answer.
     *
     * @param  array{files: array<string, string>, tests: list<array{item: int, file: string, name: string}>}  $kept
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    protected function withKept(array $kept, array $output): array
    {
        return [
            'files' => [
                ...array_map(fn (string $path, string $contents) => ['path' => $path, 'contents' => $contents], array_keys($kept['files']), $kept['files']),
                ...(is_array($output['files'] ?? null) ? array_values($output['files']) : []),
            ],
            'tests' => [...$kept['tests'], ...(is_array($output['tests'] ?? null) ? array_values($output['tests']) : [])],
        ];
    }

    /**
     * Say what was refused and ask again only for that: the files kept
     * stay as they are.
     *
     * @param  array{files: array<string, string>, tests: list<array{item: int, file: string, name: string}>, refused: array<string, list<string>>, others: list<string>, problems: list<string>}  $sorted
     */
    protected function retry(array $sorted): string
    {
        if ($sorted['files'] === []) {
            return "## Your previous tests were refused\n\n".implode("\n", $sorted['problems'])."\nReturn every file and test again, with this fixed.";
        }

        $refused = array_map(fn (string $path, array $reasons) => "- {$path}: ".implode(' ', $reasons), array_keys($sorted['refused']), $sorted['refused']);

        return implode("\n\n", array_filter([
            "## Some of your previous tests were refused\n\nThese files were kept as they are. Do not return them, and do not write tests for their items again:\n\n".implode("\n", array_map(fn (array $test) => "- {$test['file']}: {$test['name']} (item {$test['item']})", $sorted['tests'])),
            $refused === [] ? null : "These files were refused, each for the reason given:\n\n".implode("\n", $refused),
            $sorted['others'] === [] ? null : "Also:\n\n- ".implode("\n- ", $sorted['others']),
            'Return only the files for the items that have no kept test, each with its test, with this fixed. A refused file may keep its path.',
        ]));
    }

    /**
     * Write the plan's tests into the workspace as they were written, and
     * get the ones the coder had changed or removed. Run before the coder
     * starts, so it builds against them, and after it, so the change holds
     * them exactly.
     *
     * @return list<string> The paths that were not as written
     */
    public function place(Workspace $workspace, Plan $plan): array
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $changed = [];

        foreach ($plan->writtenFiles as $path => $contents) {
            if (rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false) !== $contents) {
                $driver->writeFile((string) $workspace->driver_id, $path, $contents);
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * Get the written tests a worker handed back changed. A worker builds
     * in its own copy, where they cannot be put back, so its code was made
     * to pass its own version of them. A file it left out is put back as
     * written, which changes nothing. Each test is named when its own part
     * of the file changed; otherwise the file is.
     *
     * @return list<array{file: string, name: string|null}>
     */
    public function changed(Workspace $workspace, Plan $plan): array
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $changed = [];

        foreach ($plan->writtenFiles as $path => $contents) {
            $now = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);

            if ($now === null || $now === $contents) {
                continue;
            }

            $tests = array_values(array_filter($plan->writtenTests, fn (array $test) => $test['file'] === $path && WrittenTests::body($now, WrittenTests::name($test['name'])) !== WrittenTests::body($contents, WrittenTests::name($test['name']))));

            array_push($changed, ...($tests === [] ? [['file' => $path, 'name' => null]] : array_map(fn (array $test) => ['file' => $path, 'name' => $test['name']], $tests)));
        }

        return $changed;
    }

    /**
     * Have the writer correct one written test that held the change back
     * the same way twice while all else passed (StuckWrittenTests). It sees
     * the plan's item, what the test said when it failed and what the app
     * now offers, and the same rules apply. Only that test may change: its
     * name and the file's other tests stay. Null when no answer kept the
     * rules; the test then stays as written.
     *
     * @param  array{item: int, file: string, name: string, message: string}  $test
     *
     * @throws ProvidersUnavailable
     * @throws RunCancelled
     */
    public function rewrite(Run $run, Plan $plan, Workspace $workspace, array $test): ?Plan
    {
        $item = $plan->verifyItems()[$test['item'] - 1] ?? null;
        $contents = $plan->writtenFiles[$test['file']] ?? null;

        if ($item === null || $contents === null) {
            return null;
        }

        $key = WrittenTests::name($test['name']);
        $others = array_values(array_filter($plan->writtenTests, fn (array $written) => $written['file'] === $test['file'] && WrittenTests::name($written['name']) !== $key));
        $routes = $this->gatherPlanningContext->routes($workspace);
        $files = array_column(PatchSummary::files($run->featureRequest->patch), 'path');

        $prompt = implode("\n\n", array_filter([
            "## The change\n\n{$plan->summary}",
            "## The test to correct\n\nThe test \"{$test['name']}\" in {$test['file']} was written before the change was built, to check this item: {$item['text']}\n\nThe change was built and tried again. Each time, every other test and check passed, but this test failed the same way. It may expect what the plan never asked for, such as an address or a name the app does not have. Rewrite only this test, so it checks the same item through what the app now offers. Keep its name, and keep every other test in the file exactly as it is. Return the whole file, with this test for item 1.",
            "## What it said when it failed\n\n```\n".Secrets::redact(Str::limit($test['message'], 2000))."\n```",
            "## {$test['file']} as written\n\n```php\n{$contents}\n```",
            $routes === [] ? null : "## Addresses in the app now\n\n- ".implode("\n- ", $routes),
            $files === [] ? null : "## Files the change added or changed\n\n- ".implode("\n- ", $files),
        ]));
        $attempts = max(1, (int) config('builder.verification.written_first.attempts'));

        for ($attempt = 1; ; $attempt++) {
            RunCancelled::throwIfCancelling($run);

            try {
                $response = app(AiAttempts::class)->for($run, fn () => TestWriter::make()->prompt($prompt, provider: ModelRole::Reviewer->providers()));
            } catch (FailoverableException $exception) {
                throw ProvidersUnavailable::afterFailover($exception);
            } catch (RequestException $exception) {
                $stop = ProvidersUnavailable::fromResponse($exception);
                $run->recordEvent('ai_service_error', ['reason' => $stop->reason()->value, ...(array) $stop->serviceError()]);

                throw $stop;
            }

            $this->recordModelUsage->handle($run, ModelRole::Reviewer, $response);

            try {
                if (! $response instanceof StructuredAgentResponse) {
                    throw new ConstructionFailed(__('Return the file and the test as structured output.'));
                }

                $written = WrittenTests::check($response->structured, [$item['kind']], fn () => false);
                $corrected = $written['files'][$test['file']] ?? null;
                $problems = array_filter([
                    $corrected === null || count($written['files']) !== 1 ? (string) __('Return only :file.', ['file' => $test['file']]) : null,
                    WrittenTests::name($written['tests'][0]['name']) !== $key || $written['tests'][0]['file'] !== $test['file'] ? (string) __('Keep the test\'s name: ":name".', ['name' => $test['name']]) : null,
                    $corrected !== null && array_filter($others, fn (array $other) => WrittenTests::body($corrected, WrittenTests::name($other['name'])) !== WrittenTests::body($contents, WrittenTests::name($other['name']))) !== [] ? (string) __('Keep every other test in the file exactly as it is.') : null,
                ]);

                if ($problems !== [] || $corrected === null) {
                    throw new ConstructionFailed(implode("\n", $problems));
                }

                return $plan->withWrittenTests([...$plan->writtenFiles, $test['file'] => $corrected], $plan->writtenTests);
            } catch (ConstructionFailed $exception) {
                if ($attempt >= $attempts) {
                    return null;
                }

                $prompt .= "\n\n## Your previous answer was refused\n\n{$exception->getMessage()}\nReturn the file and the test again, with this fixed.";
            }
        }
    }

    /**
     * Describe the plan and how the app's tests are written.
     *
     * @param  list<array{criterion: string, kind: string, text: string}>  $items
     * @param  list<string>  $existing  The app's test files and composer.json
     */
    protected function prompt(Plan $plan, array $items, PlanningContext $context, Workspace $workspace, array $existing): string
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $read = fn (string $path) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), '', report: false);
        $numbered = array_map(fn (int $index, array $item) => ($index + 1).". {$item['text']}", array_keys($items), $items);
        $steps = array_map(fn (array $step) => "- {$step['label']} ({$step['file']}, {$step['symbol']}): {$step['detail']}", $plan->steps);

        $sections = [
            "## The change\n\n{$plan->summary}",
            "## Acceptance criteria\n\n- ".implode("\n- ", $plan->acceptanceCriteria),
            "## What the tests must check\n\n".implode("\n", $numbered),
            "## Steps\n\n".($steps === [] ? '(none)' : implode("\n", $steps)),
        ];

        if ($plan->dataShape !== []) {
            $sections[] = "## Records the change stores\n\n".json_encode($plan->dataShape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if ($plan->assumptions !== []) {
            $sections[] = "## Assumptions\n\n- ".implode("\n- ", $plan->assumptionTexts());
        }

        if ($context->routes !== []) {
            $sections[] = "## Addresses in the app\n\n- ".implode("\n- ", $context->routes);
        }

        $framework = str_contains($read('composer.json'), '"pestphp/pest"') ? 'Pest' : 'PHPUnit';
        $sections[] = "## How the app's tests are written\n\nThe app's tests use {$framework}. Only tests under ".Capability::suiteLocation().' are run.';

        // A few of the app's own feature tests, in a fixed order, to copy their style.
        $samples = array_slice(array_values(array_filter($existing, fn (string $path) => str_starts_with($path, 'tests/Feature/') && Capability::runBySuite($path))), 0, 2);

        foreach ($samples as $path) {
            $sections[] = "## {$path}\n\n```php\n".Str::limit($read($path), (int) config('builder.verification.written_first.sample_bytes'), "\n// …")."\n```";
        }

        return implode("\n\n", $sections);
    }
}
