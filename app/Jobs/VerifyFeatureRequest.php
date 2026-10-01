<?php

namespace App\Jobs;

use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Context\ProjectNotes;
use App\Enums\VerificationStatus;
use App\Features\AcceptanceSuite;
use App\Features\AppRoutes;
use App\Features\AppTraces;
use App\Features\CodeShortcuts;
use App\Features\NewCode;
use App\Features\NewTests;
use App\Features\PatchSummary;
use App\Features\ScreenCheck;
use App\Features\TestMap;
use App\Features\TestReport;
use App\Models\FeatureRequest;
use App\Models\TestObservation;
use App\Models\Verification;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Projects\ProjectRepository;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\Drivers\CopyExclusions;
use App\Workspaces\Exceptions\CommandLost;
use App\Workspaces\WorkspaceFiles;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VerifyFeatureRequest implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: installs plus the full check suite.
     * Keep the queue connection's retry_after above this value.
     */
    public int $timeout = 3600;

    /**
     * Verification is not retried automatically; the owner can run it again.
     */
    public int $tries = 1;

    /**
     * Maximum characters of command output kept per result.
     */
    protected const OUTPUT_TAIL = 4000;

    public const OUTCOME_PASSED = 'passed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_ERRORED = 'errored';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_NOT_APPLICABLE = 'not_applicable';

    /**
     * @var list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string, tests?: list<array{file: string, name: string, outcome: string}>, at_start?: string, new_problems?: list<string>}>
     */
    protected array $results = [];

    /**
     * The whole output of each check, by its place in the results, to
     * compare with the same check on the starting commit.
     *
     * @var array<int, string>
     */
    protected array $outputs = [];

    /**
     * Whether each file the change touches was deleted, by path.
     *
     * @var array<string, bool>
     */
    protected array $touched = [];

    /**
     * What running the app showed about the change itself, by kind.
     *
     * @var array<string, mixed>
     */
    protected array $evidence = [];

    /**
     * The requests recorded while the tests used the app, from AppTraces::parse().
     *
     * @var list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null}>, blind: list<string>, cut: bool}>
     */
    protected array $requests = [];

    /**
     * Files whose change alters what setup installs. A check cannot be run
     * on the starting commit with the change's packages, so a change to one
     * of these is judged on its own result.
     */
    protected const PACKAGE_FILES = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'];

    /**
     * Create a new job instance.
     */
    public function __construct(public Verification $verification)
    {
        // On a queue of its own, a change's checks start as soon as it is
        // built, instead of waiting behind other changes being built.
        $this->onQueue(config('builder.verification.queue'));
    }

    /**
     * Copy the project into a fresh workspace, apply the change and its
     * ancestors, run setup and checks, then the protected acceptance suite,
     * and record every result.
     *
     * The verification only passes when every check passes and the protected
     * acceptance suite passes. Without an applicable suite it is "unverified".
     */
    public function handle(
        WorkspaceManager $workspaces,
        ProvisionWorkspace $provisionWorkspace,
        RunWorkspaceCommand $runWorkspaceCommand,
        DestroyWorkspace $destroyWorkspace,
        ProjectRepository $repository,
        WorkspaceFiles $workspaceFiles,
    ): void {
        $featureRequest = $this->verification->featureRequest;
        $project = $featureRequest->project;
        $workspace = null;

        $this->verification->update(['status' => VerificationStatus::Running, 'started_at' => now()]);

        try {
            $this->touched = $this->touchedFiles($featureRequest);
            $workspace = $provisionWorkspace->handle($project->owner, (string) config('builder.verification.workspace_driver'));
            $this->verification->update(['workspace_id' => $workspace->id]);

            $driver = $workspaces->driver($workspace->driver);
            $repository->withCheckout($project, $featureRequest->base_revision, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

            foreach ($featureRequest->lineage() as $position => $request) {
                $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $request->patch);

                $command = $runWorkspaceCommand->handle($workspace, ['git', 'apply', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), $patch], 120);

                if (! $this->record("Apply change #{$request->id}", 'apply', $command)) {
                    $this->skipRemaining(['setup', 'checks'], $featureRequest);
                    $this->finish(VerificationStatus::Errored, __('The change does not apply to the project.'));

                    return;
                }
            }

            $runWorkspaceCommand->handle($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30);

            if (! $this->runSteps($driver, $runWorkspaceCommand, $workspace, 'setup')) {
                $this->skipRemaining(['checks'], $featureRequest);
                $this->finish(VerificationStatus::Errored, __('A setup step failed, so the checks did not run.'));

                return;
            }

            $workspaceFiles->sync($project, $workspace);

            $checksPassed = $this->runSteps($driver, $runWorkspaceCommand, $workspace, 'checks', stopOnFailure: false);

            if (! $checksPassed) {
                $this->compareWithStart($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            }
            $this->auditPackages($runWorkspaceCommand, $workspace);
            $this->observeShortcuts($driver, $runWorkspaceCommand, $workspace, $featureRequest);

            // Before the protected acceptance tests are copied in, so the map
            // only ever holds the project's own tests.
            $this->observeTests($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            $acceptance = $this->runAcceptance($driver, $runWorkspaceCommand, $workspace, $featureRequest);

            if ($checksPassed && in_array($acceptance, [self::OUTCOME_PASSED, self::OUTCOME_NOT_APPLICABLE], true)) {
                $this->observeScreens($driver, $runWorkspaceCommand, $workspace, $featureRequest);

                // Last: it takes the change out of the workspace and does
                // not put all of it back.
                $this->observeChange($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            }

            $this->observeTraces($featureRequest);

            $this->finish(match (true) {
                $acceptance === self::OUTCOME_ERRORED => VerificationStatus::Errored,
                ! $checksPassed || $acceptance === self::OUTCOME_FAILED => VerificationStatus::Failed,
                $acceptance === self::OUTCOME_NOT_APPLICABLE => VerificationStatus::Unverified,
                default => VerificationStatus::Passed,
            });
        } catch (Throwable $exception) {
            if (! $exception instanceof CommandLost) {
                report($exception);
            }

            $this->finish(VerificationStatus::Errored, __('The checks stopped because of a problem on our side. This is our fault.'), interrupted: true);
        } finally {
            if ($workspace !== null) {
                rescue(fn () => $destroyWorkspace->handle($workspace));
            }
        }
    }

    /**
     * Record an unexpected failure (for example a worker timeout) on the verification.
     */
    public function failed(?Throwable $exception): void
    {
        $this->verification->update([
            'status' => VerificationStatus::Errored,
            'error' => __('The checks stopped because of a problem on our side. This is our fault.'),
            'interrupted' => true,
            'finished_at' => now(),
        ]);

        app(CompleteRunVerification::class)->handle($this->verification);
    }

    /**
     * Run the configured setup commands or checks. A step with a "report"
     * writes a JUnit report there, and the tests it lists are recorded with
     * the step's result: what actually ran, not what anyone says ran.
     */
    protected function runSteps(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, string $stage, bool $stopOnFailure = true): bool
    {
        $allSucceeded = true;
        $steps = $this->configuredSteps($stage);

        foreach ($steps as $index => $step) {
            $files = isset($step['files']) ? $this->changedFiles($step['files']) : null;

            if ($files === []) {
                $this->addResult($step['name'], $stage, self::OUTCOME_NOT_APPLICABLE, output: __('The change touched no files this check reads.'));

                continue;
            }

            [$command, $tests] = $this->runStep($driver, $runWorkspaceCommand, $workspace, $step, $files ?? []);

            if (! $this->record($step['name'], $stage, $command, $tests)) {
                $allSucceeded = false;

                if ($stopOnFailure) {
                    foreach (array_slice($steps, $index + 1) as $skipped) {
                        $this->addResult($skipped['name'], $stage, self::OUTCOME_SKIPPED, output: __('Not run because an earlier step failed.'));
                    }

                    return false;
                }
            }
        }

        return $allSucceeded;
    }

    /**
     * Run one step, with the given files after its command. A step with a
     * "report" writes a JUnit report there, and the tests it lists come
     * back with its command.
     *
     * @param  array{name: string, command: list<string>, timeout: int, report?: string, files?: list<string>}  $step
     * @param  list<string>  $files
     * @return array{WorkspaceCommand, list<array{file: string, name: string, outcome: string}>|null}
     */
    protected function runStep(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, array $step, array $files = []): array
    {
        $report = $step['report'] ?? null;

        if ($report !== null) {
            $runWorkspaceCommand->handle($workspace, ['rm', '-f', $report], 30);
        }

        $command = $runWorkspaceCommand->handle($workspace, [...$step['command'], ...$files], $step['timeout']);
        $tests = $report === null ? null : TestReport::fromJunit((string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $report), '', report: false));

        return [$command, $tests];
    }

    /**
     * Run each whole-app check the change failed again on the starting
     * commit, and keep with its result how it went there and which of its
     * problems are new. Problems the app already had are not the change's
     * to fix, so only the new ones are sent back. The change is put back
     * afterwards, for the checks that follow.
     */
    protected function compareWithStart(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        $steps = collect($this->configuredSteps('checks'))->keyBy('name');
        $comparable = array_keys(array_filter($this->results, fn (array $result) => $result['stage'] === 'checks' && $result['outcome'] === self::OUTCOME_FAILED && ! isset($steps[$result['name']]['files'])));

        if ($comparable === [] || array_intersect(array_keys($this->touched), self::PACKAGE_FILES) !== []) {
            return;
        }

        $lineage = $featureRequest->lineage();
        $undone = [];

        foreach (array_reverse(array_keys($lineage)) as $position) {
            if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position, reverse: true)) {
                break;
            }

            $undone[] = $position;
        }

        if (count($undone) === count($lineage)) {
            foreach ($comparable as $index) {
                [$command, $tests] = $this->runStep($driver, $runWorkspaceCommand, $workspace, $steps[$this->results[$index]['name']]);

                if ($command->lost) {
                    throw new CommandLost($command->error_output);
                }

                $result = $this->results[$index];
                $result['at_start'] = $this->outcome($command);

                if ($result['at_start'] === self::OUTCOME_FAILED) {
                    // A failed command needs a reported test failure on both
                    // sides. Missing reports or passing tests cannot explain
                    // a crash, so keep the original failure for repair.
                    if (isset($result['tests']) && (! collect($result['tests'])->contains('outcome', TestReport::FAILED) || ! collect($tests ?? [])->contains('outcome', TestReport::FAILED))) {
                        continue;
                    }

                    $result['new_problems'] = isset($result['tests']) && $tests !== null
                        ? $this->newFailingTests($result['tests'], $tests)
                        : $this->newLines($this->outputs[$index] ?? '', $this->withoutTerminalCodes($command->output."\n".$command->error_output));
                }

                $this->results[$index] = $result;
            }

            $this->verification->update(['results' => $this->results]);
        }

        foreach (array_reverse($undone) as $position) {
            if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position)) {
                throw new RuntimeException('The change could not be put back after its checks ran on the starting commit.');
            }
        }

        $runWorkspaceCommand->handle($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30);
    }

    /**
     * Apply one change of the lineage to the workspace, or take it out.
     * More `git apply` flags narrow which of its files are touched.
     *
     * @param  list<FeatureRequest>  $lineage
     * @param  list<string>  $flags
     */
    protected function applyPatch(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, array $lineage, int $position, bool $reverse = false, array $flags = []): bool
    {
        $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
        $driver->writeFile((string) $workspace->driver_id, $patch, (string) $lineage[$position]->patch);

        $command = $runWorkspaceCommand->handle($workspace, ['git', 'apply', '--whitespace=nowarn', ...($reverse ? ['--reverse'] : []), ...CopyExclusions::applyFlags(), ...$flags, $patch], 120);

        if ($command->lost) {
            throw new CommandLost($command->error_output);
        }

        return $this->outcome($command) === self::OUTCOME_PASSED;
    }

    /**
     * Name the tests that fail now but did not fail before the change.
     *
     * @param  list<array{file: string, name: string, outcome: string}>  $now
     * @param  list<array{file: string, name: string, outcome: string}>  $before
     * @return list<string>
     */
    protected function newFailingTests(array $now, array $before): array
    {
        $failing = fn (array $tests) => collect($tests)
            ->where('outcome', TestReport::FAILED)
            ->mapWithKeys(fn (array $test) => [$test['file'].'::'.$test['name'] => $test['name'].' ('.basename($test['file']).')']);

        return array_values(array_map(strval(...), $failing($now)->diffKeys($failing($before))->unique()->all()));
    }

    /**
     * Get additional occurrences of diagnostic lines. Ignore summary totals
     * and source locations, but preserve numbers in the diagnostics themselves.
     *
     * @return list<string>
     */
    protected function newLines(string $now, string $before): array
    {
        $seen = array_count_values(array_map($this->diagnosticKey(...), explode("\n", $before)));
        $new = [];

        foreach (explode("\n", $now) as $line) {
            $key = $this->diagnosticKey($line);

            if ($key === '') {
                continue;
            }

            if (($seen[$key] ?? 0) > 0) {
                $seen[$key]--;

                continue;
            }

            $new[trim($line)] = true;
        }

        return array_slice(array_keys($new), 0, 40);
    }

    /**
     * Normalize locations written by PHPStan, TypeScript and test output.
     */
    protected function diagnosticKey(string $line): string
    {
        $line = trim($line);

        if (preg_match('/^(?:Tests:|Time:|Duration:|(?:\[ERROR\] )?Found \d+ errors?\b)/', $line) === 1) {
            return '';
        }

        return (string) preg_replace(
            ['/^(\d+)(?=\s{2,}\S)/', '/\bline \d+\b/i', '/(\.[a-z]+):\d+(?::\d+)?(?=[:\s]|$)/i', '/(\.[a-z]+)\(\d+,\d+\)(?=:)/i', '/\s+/'],
            ['<line>', 'line <line>', '$1:<line>', '$1(<line>,<column>)', ' '],
            $line,
        );
    }

    /**
     * Find every file the change and its ancestors touch, later changes
     * winning, and whether it ends up deleted.
     *
     * @return array<string, bool>
     */
    protected function touchedFiles(FeatureRequest $featureRequest): array
    {
        $touched = [];

        foreach ($featureRequest->lineage() as $request) {
            foreach (PatchSummary::files($request->patch) as $file) {
                $touched[$file['path']] = preg_match('/^deleted file mode /m', $file['diff']) === 1;
            }
        }

        return $touched;
    }

    /**
     * List the files the change added or modified with one of the given
     * extensions, leaving out the notes.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    protected function changedFiles(array $extensions): array
    {
        return array_values(array_filter(
            array_keys(array_filter($this->touched, fn (bool $deleted) => ! $deleted)),
            fn (string $file) => in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true)
                && ! str_starts_with($file, ProjectNotes::directory().'/'),
        ));
    }

    /**
     * Look up known security problems in the packages the app uses. Kept
     * with the results under a stage of its own, which never decides the
     * verification's outcome.
     */
    protected function auditPackages(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace): void
    {
        /** @var array{enabled: bool, steps: list<array{name: string, report: string, command: list<string>, timeout: int}>} $config */
        $config = config('builder.verification.security');

        if (! $config['enabled']) {
            return;
        }

        foreach ($config['steps'] as $step) {
            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $problems = $command->timed_out ? null : self::knownProblems($step['report'], (string) $command->output);

            $this->addResult(
                $step['name'],
                'security',
                match (true) {
                    $problems === null => self::OUTCOME_ERRORED,
                    $problems > 0 => self::OUTCOME_FAILED,
                    default => self::OUTCOME_PASSED,
                },
                exitCode: $command->exit_code,
                timedOut: $command->timed_out,
                durationMs: $command->duration_ms,
                output: $this->withoutTerminalCodes($command->output."\n".$command->error_output),
            );
        }
    }

    /**
     * Count the high and critical problems in an audit's JSON report, or
     * null when the report cannot be read (the lookup failed).
     */
    public static function knownProblems(string $report, string $output): ?int
    {
        $data = json_decode($output, true);

        if (! is_array($data)) {
            return null;
        }

        return match ($report) {
            // Composer leaves out the ignored severities itself.
            'composer' => is_array($data['advisories'] ?? null) ? array_sum(array_map(fn ($advisories) => is_array($advisories) ? count($advisories) : 0, $data['advisories'])) : null,
            'npm' => is_array($data['metadata']['vulnerabilities'] ?? null) ? (int) ($data['metadata']['vulnerabilities']['high'] ?? 0) + (int) ($data['metadata']['vulnerabilities']['critical'] ?? 0) : null,
            default => null,
        };
    }

    /**
     * When the suite check passed, run the suite again with code coverage and
     * keep which tests ran which code (direction 22). This is evidence for
     * Effects and impact, never a check: whatever happens here, the result
     * of the verification stays what the checks said.
     */
    protected function observeTests(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, command: list<string>, timeout: int, report: string, listing: string, lines?: string} $config */
        $config = config('builder.verification.test_map');
        $suite = collect($this->results)->firstWhere('name', config('builder.verification.suite_check'));

        if (! $config['enabled'] || ($suite['outcome'] ?? null) !== self::OUTCOME_PASSED) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $featureRequest, $config) {
            $command = $runWorkspaceCommand->handle($workspace, $config['command'], $config['timeout']);
            $read = fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);
            $map = $this->outcome($command) === self::OUTCOME_PASSED ? TestMap::parse((string) $read($config['report']), $read($config['listing'])) : null;
            $lines = $map !== null && isset($config['lines']) ? $read($config['lines']) : null;

            // Read now and measured last, once the routes the change
            // added are known.
            if ($map !== null && config('builder.verification.traces.enabled')) {
                $this->requests = AppTraces::parse((string) $read((string) config('builder.verification.traces.report')));
            }

            if ($map !== null && is_string($lines)) {
                $this->keepEvidence('new_code', NewCode::measure($map, NewCode::parse($lines), $featureRequest->patch, array_keys(NewTests::files(array_map(fn (FeatureRequest $request) => $request->patch, $featureRequest->lineage())))));
            }

            TestObservation::create([
                'project_id' => $featureRequest->project_id,
                'feature_request_id' => $featureRequest->id,
                'verification_id' => $this->verification->id,
                'tests' => $map->tests ?? [],
                'files' => $map->files ?? [],
                'lines' => $map->lines ?? [],
                'error' => match (true) {
                    $map === null => __('The tests could not run with code coverage.'),
                    $map->isEmpty() => __('The tests ran, but no code coverage was recorded.'),
                    default => null,
                },
            ]);
        });
    }

    /**
     * When the change touches the app's PHP code, read the files it touched
     * for shortcuts that cost the owner later. The review reads what was
     * found; like the screen check it never changes the checks' result,
     * and when the analyser cannot run nothing is kept.
     */
    protected function observeShortcuts(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, command: list<string>, timeout: int, report: string} $config */
        $config = config('builder.verification.shortcuts');

        if (! $config['enabled'] || ! CodeShortcuts::scans($featureRequest->patch)) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $config, $featureRequest) {
            $paths = array_map(fn (string $path) => "--path={$path}", CodeShortcuts::files($featureRequest->patch));
            $command = $runWorkspaceCommand->handle($workspace, [...$config['command'], ...$paths], $config['timeout']);

            if ($this->outcome($command) !== self::OUTCOME_PASSED) {
                return;
            }

            $this->verification->update(['shortcuts' => CodeShortcuts::parse((string) $driver->readFile((string) $workspace->driver_id, $config['report']))]);
        }, report: false);
    }

    /**
     * When the change touches a screen, open the app's pages at phone,
     * tablet and computer widths and keep what was measured (direction
     * 26). The review reads it; like the test map it never changes the
     * checks' result, and when it cannot run nothing is kept.
     */
    protected function observeScreens(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, command: list<string>, timeout: int, report: string, shots_disk: string, shots_max: int} $config */
        $config = config('builder.verification.screens');

        if (! $config['enabled'] || ! ScreenCheck::scans($featureRequest->patch)) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $config, $featureRequest) {
            $shoot = ScreenCheck::shootable($featureRequest->patch, $config['shots_max']);
            $command = $runWorkspaceCommand->handle($workspace, $config['command'], $config['timeout'], ['SCREEN_CHECK_SHOOT' => implode(',', $shoot)]);

            if ($this->outcome($command) !== self::OUTCOME_PASSED) {
                return;
            }

            $screens = ScreenCheck::parse((string) $driver->readFile((string) $workspace->driver_id, $config['report']));

            if ($screens !== null) {
                $screens['shots'] = $this->keepShots($driver, $workspace, $screens['pages'], $config['shots_disk']);
            }

            $this->verification->update(['screens' => $screens]);
        }, report: false);
    }

    /**
     * Copy the pictures the screen check took out of the workspace, so they
     * outlive it. Only JPEG files the tool wrote under its own folder are
     * read; anything else a page claims is ignored.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array{screen: string, width: int, path: string}>
     */
    protected function keepShots(WorkspaceDriver $driver, Workspace $workspace, array $pages, string $disk): array
    {
        $shots = [];

        foreach ($pages as $page) {
            foreach (is_array($page['shots'] ?? null) ? $page['shots'] : [] as $shot) {
                $file = (string) ($shot['file'] ?? '');

                if (preg_match('#^storage/logs/screens/shots/[0-9]+-[0-9]+\.jpg$#', $file) !== 1) {
                    continue;
                }

                $contents = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $file), null, report: false);

                if (is_string($contents) && str_starts_with($contents, "\xFF\xD8")) {
                    $path = sprintf('screen-shots/%d/%s', $this->verification->id, basename($file));
                    Storage::disk($disk)->put($path, $contents);
                    $shots[] = ['screen' => (string) $page['screen'], 'width' => (int) $shot['width'], 'path' => $path];
                }
            }
        }

        return $shots;
    }

    /**
     * When the checks pass, measure two things about the change itself by
     * running the app with and without it. The addresses the app answers
     * are listed on both sides, and the tests the change added are run with
     * its code taken out and only its tests left in: one that still passes
     * says nothing about the change.
     *
     * Like the screen check this never changes the checks' result, and
     * what cannot be measured is not kept. A change to the packages is not
     * measured: the starting commit would need other packages installed.
     */
    protected function observeChange(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, routes: array{command: list<string>, timeout: int, report: string}, tests: array{command: list<string>, timeout: int, report: string}} $config */
        $config = config('builder.verification.change_evidence');
        $lineage = $featureRequest->lineage();
        $suite = (array) config('builder.verification.suite_paths');
        $app = array_filter(array_keys($this->touched), fn (string $path) => ! Str::startsWith($path, $suite) && ! str_starts_with($path, ProjectNotes::directory().'/'));
        $code = array_any($app, fn (string $path) => str_ends_with($path, '.php'));
        // A change that only adds tests has no code to take out: its tests
        // pass without it by design.
        $tests = $app === [] ? [] : NewTests::files(array_map(fn (FeatureRequest $request) => $request->patch, $lineage));

        if (! $config['enabled'] || ($tests === [] && ! $code) || array_intersect(array_keys($this->touched), self::PACKAGE_FILES) !== []) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $config, $lineage, $suite, $tests, $code) {
            $read = fn (string $path) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), '', report: false);
            $routes = function () use ($runWorkspaceCommand, $workspace, $config, $read) {
                $command = $runWorkspaceCommand->handle($workspace, $config['routes']['command'], $config['routes']['timeout']);

                return $this->outcome($command) === self::OUTCOME_PASSED ? AppRoutes::parse($read($config['routes']['report'])) : null;
            };
            $run = function (array $files) use ($runWorkspaceCommand, $workspace, $config, $read) {
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['tests']['report']], 30);
                $runWorkspaceCommand->handle($workspace, [...$config['tests']['command'], ...array_values($files)], $config['tests']['timeout']);

                return TestReport::fromJunit($read($config['tests']['report']));
            };
            // The protected tests replaced whatever the change put there.
            $kept = ['--exclude='.AcceptanceSuite::WORKSPACE_DIRECTORY.'/*'];

            $after = $code ? $routes() : null;

            foreach (array_reverse(array_keys($lineage)) as $position) {
                if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position, reverse: true, flags: $kept)) {
                    return;
                }
            }

            $before = $after !== null ? $routes() : null;

            if ($after !== null && $before !== null) {
                $this->keepEvidence('routes', AppRoutes::changes($before, $after) ?? []);
            }

            if ($tests === []) {
                return;
            }

            $old = array_keys(array_filter($tests));
            $ranBefore = $old === [] ? [] : $run($old);
            $only = array_values(array_map(fn (string $path) => '--include='.rtrim($path, '/').'/*', $suite));

            // Put back only what the change did under the tests' folders:
            // its tests and the helpers they use.
            foreach ($lineage as $position => $request) {
                $touchesTests = array_any(PatchSummary::files($request->patch), fn (array $file) => Str::startsWith($file['path'], $suite));

                if ($touchesTests && ! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position, flags: [...$kept, ...$only])) {
                    return;
                }
            }

            $ranWithout = $run(array_keys($tests));

            // No report means the tests could not start without the change,
            // which says nothing about any one of them.
            if ($ranWithout !== []) {
                $this->keepEvidence('new_tests', NewTests::found($tests, $ranBefore, $ranWithout));
            }
        }, report: false);
    }

    /**
     * Measure what the app did while its tests used it against the change:
     * what the change's own lines saved, kept and sent that the shape of a
     * trace shows to be a problem (direction 32). The requests were
     * recorded in the coverage run, so nothing runs here. A lookup one
     * request repeated from a new line joins the shortcuts, which are
     * dealt with after the owner keeps the change.
     */
    protected function observeTraces(FeatureRequest $featureRequest): void
    {
        rescue(function () use ($featureRequest) {
            $measured = AppTraces::measure(
                $this->requests,
                $featureRequest->patch,
                array_column($this->evidence['routes']['added'] ?? [], 'route'),
                (int) config('builder.verification.traces.repeats'),
            );

            if ($measured === null) {
                return;
            }

            if ($measured['repeats'] !== [] && config('builder.verification.shortcuts.enabled')) {
                $this->verification->update(['shortcuts' => AppTraces::withRepeats($this->verification->shortcuts, $measured['repeats'])]);
            }

            $this->keepEvidence('traces', $measured);
        });
    }

    /**
     * Keep one kind of evidence about the change with the verification.
     */
    protected function keepEvidence(string $kind, mixed $evidence): void
    {
        if ($evidence === null) {
            return;
        }

        $this->evidence[$kind] = $evidence;
        $this->verification->update(['evidence' => $this->evidence]);
    }

    /**
     * Replace tests/Acceptance with the platform-owned suite for the change,
     * run it with the platform's runner configuration, and return its outcome.
     */
    protected function runAcceptance(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): string
    {
        $tests = $featureRequest->acceptance ?? [];

        if ($tests === []) {
            $this->addResult(__('Protected acceptance tests'), 'acceptance', self::OUTCOME_NOT_APPLICABLE, output: __('No protected acceptance tests apply to this change.'));

            return self::OUTCOME_NOT_APPLICABLE;
        }

        $suite = AcceptanceSuite::fromConfig();

        try {
            $files = $suite->files($tests);
        } catch (Throwable $exception) {
            $this->addResult(__('Protected acceptance tests'), 'acceptance', self::OUTCOME_ERRORED, output: $exception->getMessage());

            return self::OUTCOME_ERRORED;
        }

        $runWorkspaceCommand->handle($workspace, ['rm', '-rf', AcceptanceSuite::WORKSPACE_DIRECTORY], 30);

        foreach ($files as $path => $contents) {
            $driver->writeFile((string) $workspace->driver_id, $path, $contents);
        }

        $command = $runWorkspaceCommand->handle($workspace, $suite->command(), (int) config('builder.verification.acceptance.timeout'));

        $this->record(__('Protected acceptance tests'), 'acceptance', $command);

        return $this->outcome($command);
    }

    /**
     * Record every step of the given stages (and the acceptance suite) as skipped.
     *
     * @param  list<string>  $stages
     */
    protected function skipRemaining(array $stages, FeatureRequest $featureRequest): void
    {
        foreach ($stages as $stage) {
            foreach ($this->configuredSteps($stage) as $step) {
                $this->addResult($step['name'], $stage, self::OUTCOME_SKIPPED, output: __('Not run because an earlier step failed.'));
            }
        }

        if (($featureRequest->acceptance ?? []) !== []) {
            $this->addResult(__('Protected acceptance tests'), 'acceptance', self::OUTCOME_SKIPPED, output: __('Not run because an earlier step failed.'));
        }
    }

    /**
     * Get the configured setup commands or checks.
     *
     * @return list<array{name: string, command: list<string>, timeout: int, report?: string, files?: list<string>}>
     */
    protected function configuredSteps(string $stage): array
    {
        /** @var list<array{name: string, command: list<string>, timeout: int, report?: string, files?: list<string>}> $steps */
        $steps = config("builder.verification.{$stage}", []);

        return $steps;
    }

    /**
     * Add a command's outcome to the results and report whether it passed.
     *
     * @param  list<array{file: string, name: string, outcome: string}>|null  $tests  The tests the command ran, when it reports them
     */
    protected function record(string $name, string $stage, WorkspaceCommand $command, ?array $tests = null): bool
    {
        if ($command->lost) {
            $this->addResult($name, $stage, self::OUTCOME_ERRORED, timedOut: true, durationMs: $command->duration_ms, output: $command->error_output);

            throw new CommandLost($command->error_output);
        }

        $outcome = $this->outcome($command);
        $this->outputs[count($this->results)] = $this->withoutTerminalCodes($command->output."\n".$command->error_output);

        $this->addResult(
            $name,
            $stage,
            $outcome,
            exitCode: $command->exit_code,
            timedOut: $command->timed_out,
            durationMs: $command->duration_ms,
            output: $this->withoutTerminalCodes($command->output."\n".$command->error_output),
            tests: $tests,
        );

        return $outcome === self::OUTCOME_PASSED;
    }

    /**
     * Classify a finished command: a timeout is an error, not a failed check.
     */
    protected function outcome(WorkspaceCommand $command): string
    {
        return match (true) {
            $command->timed_out => self::OUTCOME_ERRORED,
            $command->exit_code === 0 => self::OUTCOME_PASSED,
            default => self::OUTCOME_FAILED,
        };
    }

    /**
     * Append a result and save progress so the owner sees it while the run continues.
     *
     * @param  list<array{file: string, name: string, outcome: string}>|null  $tests
     */
    protected function addResult(string $name, string $stage, string $outcome, ?int $exitCode = null, bool $timedOut = false, int $durationMs = 0, string $output = '', ?array $tests = null): void
    {
        $output = trim($output);

        $this->results[] = [
            'name' => $name,
            'stage' => $stage,
            'outcome' => $outcome,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'duration_ms' => $durationMs,
            'output' => mb_strlen($output) > self::OUTPUT_TAIL ? '…'.Str::substr($output, -self::OUTPUT_TAIL) : $output,
            ...($tests === null ? [] : ['tests' => $tests]),
        ];

        $this->verification->update(['results' => $this->results]);
    }

    /**
     * Remove ANSI colour and cursor sequences so output reads as plain text.
     */
    protected function withoutTerminalCodes(string $output): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output);
    }

    /**
     * Store the final status.
     */
    protected function finish(VerificationStatus $status, ?string $error = null, bool $interrupted = false): void
    {
        $this->verification->update([
            'status' => $status,
            'results' => $this->results,
            'error' => $error,
            'interrupted' => $interrupted,
            'finished_at' => now(),
        ]);

        app(CompleteRunVerification::class)->handle($this->verification);
    }
}
