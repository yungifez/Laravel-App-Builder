<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Ai\Agents\TestWriter;
use App\Context\Capability;
use App\Enums\ModelRole;
use App\Features\WrittenTests;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\ProvidersUnavailable;
use App\Runs\Plan;
use App\Runs\PlanningContext;
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
    ) {}

    /**
     * Have another model write the tests for each item the plan's tests
     * must check, before the change is built (§12). The coder cannot fit
     * them to its own code: they are written into the workspace before it
     * starts and put back after it finishes. Output that breaks the rules
     * in WrittenTests is asked for once more; when it still breaks them,
     * the change is built as before, with tests the coder writes.
     */
    public function handle(Run $run, Plan $plan, Workspace $workspace, PlanningContext $context): Plan
    {
        $items = $plan->verifyItems();

        // A worker builds in its own copy of the app, so only our agents
        // start from written tests.
        if (! config('builder.verification.written_first.enabled') || $run->driver !== 'sdk' || $items === []) {
            return $plan;
        }

        $existing = array_values(array_filter(explode("\0", $this->runWorkspaceCommand->handle(
            $workspace,
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', 'tests', 'composer.json'],
            120,
        )->output)));

        $prompt = $this->prompt($plan, $items, $context, $workspace, $existing);
        $attempts = max(1, (int) config('builder.verification.written_first.attempts'));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = TestWriter::make()->prompt($prompt, provider: ModelRole::Reviewer->providers());
            } catch (FailoverableException $exception) {
                throw ProvidersUnavailable::because($exception);
            } catch (RequestException $exception) {
                $stop = ProvidersUnavailable::fromResponse($exception);
                $run->recordEvent('ai_service_error', ['reason' => $stop->reason(), ...(array) $stop->serviceError()]);

                throw $stop;
            }

            $this->recordModelUsage->handle($run, ModelRole::Reviewer, $response);

            try {
                if (! $response instanceof StructuredAgentResponse) {
                    throw new ConstructionFailed(__('Return the files and the tests as structured output.'));
                }

                $written = WrittenTests::check($response->structured, count($items), fn (string $path) => in_array($path, $existing, true));
                $run->recordEvent('tests_written', ['files' => array_keys($written['files']), 'tests' => count($written['tests'])]);

                return $plan->withWrittenTests($written['files'], $written['tests']);
            } catch (ConstructionFailed $exception) {
                if ($attempt >= $attempts) {
                    $run->recordEvent('tests_not_written', ['error' => Str::limit($exception->getMessage(), 2000)]);

                    return $plan;
                }

                $prompt .= "\n\n## Your previous tests were refused\n\n{$exception->getMessage()}\nReturn every file and test again, with this fixed.";
            }
        }
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
            $sections[] = "## Assumptions\n\n- ".implode("\n- ", $plan->assumptions);
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
