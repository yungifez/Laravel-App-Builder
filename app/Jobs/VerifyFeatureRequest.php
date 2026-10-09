<?php

namespace App\Jobs;

use App\Actions\Context\ReadProjectContext;
use App\Actions\Previews\CountRowsFailingFormat;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\VisualEditing\CommitDesignEdits;
use App\Actions\Workspaces\CheckStepNeeds;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Context\Capability;
use App\Context\ProjectNotes;
use App\Enums\ChecksStoppedBecause;
use App\Enums\ExperimentStatus;
use App\Enums\VerificationStatus;
use App\Features\AcceptanceSuite;
use App\Features\AccessProbes;
use App\Features\AppBoundaries;
use App\Features\AppContainment;
use App\Features\AppConventions;
use App\Features\AppCoupling;
use App\Features\AppDrift;
use App\Features\AppFaults;
use App\Features\AppRoutes;
use App\Features\AppTraces;
use App\Features\BoundaryCode;
use App\Features\CodeShortcuts;
use App\Features\InputProbes;
use App\Features\MigrationChecks;
use App\Features\Mutants;
use App\Features\NarrowedFormats;
use App\Features\NewCode;
use App\Features\NewMessages;
use App\Features\NewTests;
use App\Features\OwnedRecords;
use App\Features\PackagePolicy;
use App\Features\PatchSummary;
use App\Features\PhoneAppSecrets;
use App\Features\ProtectedInputs;
use App\Features\QueuedWork;
use App\Features\ReplayProbes;
use App\Features\RoleProbes;
use App\Features\ScreenCheck;
use App\Features\StrictModels;
use App\Features\SwapProbes;
use App\Features\TestMap;
use App\Features\TestRefusals;
use App\Features\TestReport;
use App\Features\TimeShifts;
use App\Models\FeatureRequest;
use App\Models\TestObservation;
use App\Models\Verification;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Projects\ProjectRepository;
use App\Runs\Plan;
use App\Scaffolding\Scaffold;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\Drivers\CopyExclusions;
use App\Workspaces\Exceptions\CommandLost;
use App\Workspaces\WorkspaceFiles;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-import-type Record from Scaffold
 * @phpstan-import-type Probe from RoleProbes
 * @phpstan-import-type Tenant from RoleProbes
 */
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
     * @var list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool}>, blind: list<string>, cut: bool, n?: int, fault?: int}>
     */
    protected array $requests = [];

    /**
     * What reading the change's code found against the boundary rules,
     * from BoundaryCode::inPatch(), read while the change is still in the
     * workspace.
     *
     * @var array{read: list<array{kind: string, what: string, at: string, in: string}>, before: list<array{kind: string, what: string, at: string, in: string}>}
     */
    protected array $boundaryCode = ['read' => [], 'before' => []];

    /**
     * Whether the suite check ran with code coverage and passed, so the
     * test map can be read from what it wrote.
     */
    protected bool $mapped = false;

    /**
     * Which tests ran which lines, once the suite has run with coverage.
     */
    protected ?TestMap $testMap = null;

    /**
     * Files whose change alters what setup installs. A check cannot be run
     * on the starting commit with the change's packages, so a change to one
     * of these is judged on its own result.
     */
    protected const PACKAGE_FILES = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'];

    /**
     * The manifest and lock file each security lookup reads, by report.
     */
    protected const AUDIT_FILES = ['composer' => ['composer.json', 'composer.lock'], 'npm' => ['package.json', 'package-lock.json']];

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
            $manifests = $this->readManifests($driver, $workspace);

            // A copy with no repository of its own inside another one makes
            // git skip every path outside it and still say it applied.
            $runWorkspaceCommand->handle($workspace, ['git', 'init', '--quiet'], 60);

            foreach ($featureRequest->lineage() as $position => $request) {
                $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $request->patch);

                $command = $runWorkspaceCommand->handle($workspace, ['git', 'apply', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), $patch], 120);

                if (! $this->record("Apply change #{$request->id}", 'apply', $command)) {
                    $this->skipRemaining(['setup', 'checks'], $featureRequest);
                    $this->finish(VerificationStatus::Errored, stoppedBecause: ChecksStoppedBecause::DoesNotApply);

                    return;
                }
            }

            $runWorkspaceCommand->handle($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30);

            if (! $this->guardProtectedInputs($driver, $workspace, $manifests)) {
                $this->skipRemaining(['setup', 'checks'], $featureRequest);
                $this->finish(VerificationStatus::Failed, stoppedBecause: ChecksStoppedBecause::ProtectedInputs);

                return;
            }

            if (! $this->runSteps($driver, $runWorkspaceCommand, $workspace, 'setup')) {
                $this->skipRemaining(['checks'], $featureRequest);
                $this->finish(VerificationStatus::Errored, stoppedBecause: array_intersect(array_keys($this->touched), self::PACKAGE_FILES) !== [] ? ChecksStoppedBecause::ChangeInstall : ChecksStoppedBecause::Setup);

                return;
            }

            $workspaceFiles->sync($project, $workspace);

            $checksPassed = $this->runSteps($driver, $runWorkspaceCommand, $workspace, 'checks', stopOnFailure: false);

            if (! $checksPassed) {
                $this->compareWithStart($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            }
            $this->auditPackages($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            $checksPassed = $this->checkPhoneSecrets($driver, $workspace, $featureRequest) && $checksPassed;
            $this->observeShortcuts($driver, $runWorkspaceCommand, $workspace, $featureRequest);

            // Before the protected acceptance tests are copied in, so the map
            // only ever holds the project's own tests.
            $this->observeTests($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            $acceptance = $this->runAcceptance($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            $this->readBoundaryCode($driver, $workspace, $featureRequest);
            $this->checkMigrations($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            $this->readQueuedWork($driver, $workspace, $featureRequest);
            $this->readOwnedRecords($driver, $workspace, $featureRequest);
            $this->countNarrowedFormats($featureRequest);
            $this->readPackages($driver, $workspace, $featureRequest);
            $this->readMessages($driver, $workspace, $featureRequest);

            $accepted = in_array($acceptance, [self::OUTCOME_PASSED, self::OUTCOME_NOT_APPLICABLE], true);
            $clean = $checksPassed && $accepted;

            // A check that failed the same way before the change is the app's
            // old problem, not the change's, so it does not stop the probes:
            // each writes and judges its own tests.
            if (($checksPassed || $this->onlyOldFailures()) && $accepted) {
                $checksPassed = $this->probeAccess($driver, $runWorkspaceCommand, $workspace, $featureRequest) && $checksPassed;
                $checksPassed = $this->probeRoles($driver, $runWorkspaceCommand, $workspace, $featureRequest) && $checksPassed;
                $checksPassed = $this->shiftTime($driver, $runWorkspaceCommand, $workspace, $featureRequest) && $checksPassed;
                $checksPassed = $this->checkStrictModels($runWorkspaceCommand, $workspace, $featureRequest) && $checksPassed;
                $checksPassed = $this->replayForms($driver, $runWorkspaceCommand, $workspace, $featureRequest) && $checksPassed;
                $checksPassed = $this->probeInputs($driver, $runWorkspaceCommand, $workspace, $featureRequest) && $checksPassed;
            }

            // What follows reads the app's own tests, which an old failure
            // would blur, so it waits for every check to pass.
            if ($clean) {
                $this->observeScreens($driver, $runWorkspaceCommand, $workspace, $featureRequest);
                $this->observeFaults($driver, $runWorkspaceCommand, $workspace, $featureRequest);
                $this->observeMutants($driver, $runWorkspaceCommand, $workspace, $featureRequest);

                // Last: it takes the change out of the workspace and does
                // not put all of it back.
                $this->observeChange($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            }

            $this->observeTraces($featureRequest);
            $this->observeBoundaries($featureRequest);
            $this->observeContainment($featureRequest);
            $this->observeDrift($featureRequest);
            $this->observeConventions($featureRequest);
            $this->observeCoupling($featureRequest);

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
        $needs = app(CheckStepNeeds::class);

        foreach ($steps as $index => $step) {
            // An app without the file a step needs has no use for it.
            if (! $needs->met($workspace, $step)) {
                $this->addResult($step['name'], $stage, self::OUTCOME_NOT_APPLICABLE, output: __('The app has no :file, so this does not apply to it.', ['file' => $step['needs'] ?? '']));

                continue;
            }

            $files = isset($step['files']) ? $this->changedFiles($step['files']) : null;

            if ($files === []) {
                $this->addResult($step['name'], $stage, self::OUTCOME_NOT_APPLICABLE, output: __('The change touched no files this check reads.'));

                continue;
            }

            [$command, $tests] = $step['name'] === config('builder.verification.suite_check')
                ? $this->runSuite($driver, $runWorkspaceCommand, $workspace, $step)
                : $this->runStep($driver, $runWorkspaceCommand, $workspace, $step, $files ?? []);

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
     * Run the app's test suite. When the test map is on, the suite runs
     * once with code coverage and the trace recorder, and that run is the
     * check, so the suite does not run a second time for the map. Should
     * that run fail for any reason, the plain command runs and decides the
     * check, so the extra recording can never fail a change.
     *
     * @param  array{name: string, command: list<string>, timeout: int, report?: string}  $step
     * @return array{WorkspaceCommand, list<array{file: string, name: string, outcome: string}>|null}
     */
    protected function runSuite(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, array $step): array
    {
        /** @var array{enabled: bool, command: list<string>, timeout: int} $map */
        $map = config('builder.verification.test_map');

        if ($map['enabled'] && isset($step['report'])) {
            [$command, $tests] = $this->runStep($driver, $runWorkspaceCommand, $workspace, [...$step, 'command' => $map['command'], 'timeout' => $map['timeout']]);

            // Only a passing run whose report lists what ran stands for the
            // check; anything else is decided by the plain command.
            if ($command->lost || ($this->outcome($command) === self::OUTCOME_PASSED && $tests !== null && $tests !== [])) {
                $this->mapped = ! $command->lost;

                return [$command, $tests];
            }
        }

        return $this->runStep($driver, $runWorkspaceCommand, $workspace, $step);
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
     * Determine if every check that failed also failed on the starting
     * commit, with no problem the change brought.
     */
    protected function onlyOldFailures(): bool
    {
        return ! collect($this->results)->contains(fn (array $result) => $result['stage'] === 'checks'
            && $result['outcome'] === self::OUTCOME_FAILED
            && ! (($result['at_start'] ?? null) === self::OUTCOME_FAILED && ($result['new_problems'] ?? []) === []));
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
     * verification's outcome. A lookup that finds problems is compared
     * with the starting commit, so problems the app already had are not
     * put down to the change.
     */
    protected function auditPackages(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, steps: list<array{name: string, report: string, command: list<string>, timeout: int, needs?: string}>} $config */
        $config = config('builder.verification.security');

        if (! $config['enabled']) {
            return;
        }

        $needs = app(CheckStepNeeds::class);

        foreach ($config['steps'] as $step) {
            // An app with no JavaScript (or PHP) packages has none to look
            // up, so a clean result elsewhere speaks for all it uses.
            if (! $needs->met($workspace, $step)) {
                $this->addResult($step['name'], 'security', self::OUTCOME_NOT_APPLICABLE, output: __('The app has no :file, so this does not apply to it.', ['file' => $step['needs'] ?? '']));

                continue;
            }

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

            if ($problems > 0) {
                $this->compareAuditWithStart($driver, $runWorkspaceCommand, $workspace, $featureRequest, $step, count($this->results) - 1, (string) $command->output);
            }
        }
    }

    /**
     * Fail a phone app whose settings give a secret away. The settings ship
     * inside the app, so this is ours to check, not the app's own tests'.
     * Other apps have no such file inside them, so they skip it.
     */
    protected function checkPhoneSecrets(WorkspaceDriver $driver, Workspace $workspace, FeatureRequest $featureRequest): bool
    {
        if ($featureRequest->project->parent_id === null) {
            return true;
        }

        $settings = (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, '.env.example'), '', report: false);
        $secrets = PhoneAppSecrets::find($settings);

        $this->addResult(__('Phone app keeps no secrets'), 'checks', $secrets === [] ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, output: $secrets === [] ? '' : PhoneAppSecrets::explain($secrets));

        return $secrets === [];
    }

    /**
     * Keep with a failed lookup how it went on the starting commit, and
     * which packages' problems are new. A lookup reads only the lock file,
     * so a change that leaves it alone has the starting commit's problems.
     * A changed lock file is taken out for the lookup and put back after.
     * A changed manifest with its lock file untouched is judged on its own
     * result: the lookup may read what was installed for the change.
     *
     * @param  array{name: string, report: string, command: list<string>, timeout: int, needs?: string}  $step
     * @param  int  $index  The lookup's place in the results
     */
    protected function compareAuditWithStart(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest, array $step, int $index, string $output): void
    {
        [$manifest, $lock] = self::AUDIT_FILES[$step['report']] ?? [null, null];

        if ($lock === null) {
            return;
        }

        $result = $this->results[$index];

        if (! isset($this->touched[$lock])) {
            if (! isset($this->touched[$manifest])) {
                $result['at_start'] = self::OUTCOME_FAILED;
                $result['new_problems'] = [];
                $this->results[$index] = $result;
            }

            return;
        }

        $lineage = $featureRequest->lineage();
        $files = array_map(fn (FeatureRequest $request) => array_filter(PatchSummary::files($request->patch), fn (array $file) => in_array($file['path'], [$manifest, $lock], true)), $lineage);

        // Without a lock file at the start, the lookup would read what was
        // installed for the change, so nothing could be compared.
        if (array_any($files, fn (array $changed) => array_any($changed, fn (array $file) => $file['path'] === $lock && preg_match('/^new file mode /m', $file['diff']) === 1))) {
            return;
        }

        $positions = array_keys(array_filter($files));
        $only = ["--include={$manifest}", "--include={$lock}"];
        $undone = [];

        foreach (array_reverse($positions) as $position) {
            if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position, reverse: true, flags: $only)) {
                break;
            }

            $undone[] = $position;
        }

        if (count($undone) === count($positions)) {
            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $before = $command->timed_out ? null : self::problemPackages($step['report'], (string) $command->output);

            if ($before !== null) {
                $result['at_start'] = $before === [] ? self::OUTCOME_PASSED : self::OUTCOME_FAILED;
                $result['new_problems'] = array_values(array_diff(self::problemPackages($step['report'], $output) ?? [], $before));
                $this->results[$index] = $result;
            }
        }

        foreach (array_reverse($undone) as $position) {
            if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position, flags: $only)) {
                throw new RuntimeException('The change could not be put back after its packages were looked up on the starting commit.');
            }
        }

        $runWorkspaceCommand->handle($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30);
        $this->verification->update(['results' => $this->results]);
    }

    /**
     * Name the packages with high or critical problems in an audit's JSON
     * report, or null when the report cannot be read.
     *
     * @return list<string>|null
     */
    public static function problemPackages(string $report, string $output): ?array
    {
        $data = json_decode($output, true);

        return match (true) {
            ! is_array($data) => null,
            $report === 'composer' && is_array($data['advisories'] ?? null) => array_keys(array_filter($data['advisories'], fn ($advisories) => is_array($advisories) && $advisories !== [])),
            $report === 'npm' && is_array($data['vulnerabilities'] ?? null) => array_keys(array_filter($data['vulnerabilities'], fn ($vulnerability) => in_array($vulnerability['severity'] ?? null, ['high', 'critical'], true))),
            default => null,
        };
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

        rescue(function () use ($driver, $workspace, $featureRequest, $config) {
            // The suite check already ran with coverage when it could.
            $read = fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);
            $map = $this->mapped ? TestMap::parse((string) $read($config['report']), $read($config['listing'])) : null;
            $this->testMap = $map;
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
            $command = $runWorkspaceCommand->handle($workspace, $config['command'], $config['timeout'], [
                'SCREEN_CHECK_SHOOT' => implode(',', $shoot),
                ...($this->screensBuilt() ? ['SCREEN_CHECK_BUILT' => '1'] : []),
            ]);

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
     * Determine if setup built the screens for this change, so the screen
     * check need not build them again. Nothing after setup changes them:
     * the checks that take the change out put it back as it was.
     */
    protected function screensBuilt(): bool
    {
        $builds = array_column(array_filter($this->configuredSteps('setup'), fn (array $step) => $step['command'] === ['npm', 'run', 'build']), 'name');

        return collect($this->results)->contains(fn (array $result) => $result['stage'] === 'setup' && in_array($result['name'], $builds, true) && $result['outcome'] === self::OUTCOME_PASSED);
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
     * Only this change is taken out. One that builds on changes not kept
     * yet is measured on top of them, or their tests would count as its own.
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
        $own = array_key_last($lineage);
        $suite = (array) config('builder.verification.suite_paths');
        $app = array_filter(array_column(PatchSummary::files($featureRequest->patch), 'path'), fn (string $path) => ! Str::startsWith($path, $suite) && ! str_starts_with($path, ProjectNotes::directory().'/'));
        $code = array_any($app, fn (string $path) => str_ends_with($path, '.php'));
        // A change that only adds tests has no code to take out: its tests
        // pass without it by design.
        $tests = $app === [] ? [] : NewTests::files([$featureRequest->patch]);

        if (! $config['enabled'] || ($tests === [] && ! $code) || array_intersect(array_keys($this->touched), self::PACKAGE_FILES) !== []) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $featureRequest, $config, $lineage, $own, $suite, $tests, $code) {
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

            if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $own, reverse: true, flags: $kept)) {
                return;
            }

            $before = $after !== null ? $routes() : null;

            if ($after !== null && $before !== null) {
                $this->keepEvidence('routes', AppRoutes::changes($before, $after, AppRoutes::planned($this->plannedRecords($featureRequest))) ?? []);
            }

            if ($tests === []) {
                return;
            }

            $old = array_keys(array_filter($tests));
            $ranBefore = $old === [] ? [] : $run($old);
            $only = array_values(array_map(fn (string $path) => '--include='.rtrim($path, '/').'/*', $suite));

            // Put back only what the change did under the tests' folders:
            // its tests and the helpers they use.
            $touchesTests = array_any(PatchSummary::files($featureRequest->patch), fn (array $file) => Str::startsWith($file['path'], $suite));

            if ($touchesTests && ! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $own, flags: [...$kept, ...$only])) {
                return;
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
     * Prove that the change's migrations run, are undone and run again on
     * the workspace's database filled by the app's seeders, and note each
     * migration that already existed and that the change edits (§9, §12).
     * It is kept as evidence; the gate sends a finding back to the coder,
     * so the checks' result here does not change.
     */
    protected function checkMigrations(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, timeout: int, directory: string} $config */
        $config = config('builder.verification.migrations');
        $added = MigrationChecks::added($featureRequest->patch);
        $edited = MigrationChecks::edited($featureRequest->patch);

        if (! $config['enabled'] || ($added === [] && $edited === [])) {
            return;
        }

        $read = fn (string $file) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, "{$config['directory']}/{$file}"), '', report: false);
        $report = null;

        if ($added !== []) {
            $command = $runWorkspaceCommand->handle($workspace, ['sh', '-c', MigrationChecks::script($config['directory']), 'sh', ...$added], $config['timeout']);
            $report = $command->timed_out ? null : $read('report.json');
        }

        $this->keepEvidence('migrations', MigrationChecks::evidence($added, $edited, $report, fn (string $step) => $read("{$step}.log")));
    }

    /**
     * Read the work the change sends to the queue, while the change is
     * still in the workspace, for how it tries again and fails (§12). It
     * is kept as evidence; the gate sends a finding back to the coder.
     */
    protected function readQueuedWork(WorkspaceDriver $driver, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.queued.enabled')) {
            return;
        }

        $queued = rescue(fn () => QueuedWork::inPatch(
            $featureRequest->patch,
            fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false),
        ), []);

        $this->keepEvidence('queued', $queued === [] ? null : $queued);
    }

    /**
     * Read the tables the change gives an owner column, while the change is
     * still in the workspace, for what keeps each owner's records apart
     * (§12). It is kept as evidence; the gate sends a finding back to the
     * coder.
     */
    protected function readOwnedRecords(WorkspaceDriver $driver, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, columns: list<string>} $config */
        $config = config('builder.verification.owners');

        if (! $config['enabled']) {
            return;
        }

        $owned = rescue(fn () => OwnedRecords::inPatch(
            $featureRequest->patch,
            $config['columns'],
            fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false),
        ), []);

        $this->keepEvidence('owners', $owned === [] ? null : $owned);
    }

    /**
     * Count the saved values a stricter format would turn away, in the
     * owner's app on show, where people's records are (§9). Only the
     * counts come back. A format that could not be counted says why, for
     * the proof's coverage. It is kept as evidence; the gate sends a
     * finding back to the coder.
     */
    protected function countNarrowedFormats(FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.narrowed.enabled')) {
            return;
        }

        $narrowed = rescue(fn () => NarrowedFormats::inPatch($featureRequest->patch), [], report: false);

        if ($narrowed === []) {
            return;
        }

        $count = app(CountRowsFailingFormat::class);
        $running = $count->running($featureRequest->project) !== null;
        $counted = [];

        foreach ($narrowed as $format) {
            $rows = $running ? $count->handle($featureRequest->project, $format['table'], $format['column'], $format['kind'], $format['after']) : null;
            $format = [...$format, 'rows' => $rows['rows'] ?? null, 'failing' => $rows['failing'] ?? null];
            $counted[] = $rows !== null && $rows['rows'] > 0 ? $format : [...$format, 'reason' => NarrowedFormats::unchecked($format, $running)];
        }

        $this->keepEvidence('narrowed', $counted);
    }

    /**
     * Read the packages the change adds to the app's lockfiles, while the
     * change is still in the workspace, for the dependency policy (§12).
     * It is kept as evidence; the gate sends a finding back to the coder.
     */
    protected function readPackages(WorkspaceDriver $driver, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, allowed: array{composer: list<string>, npm: list<string>}, licenses: list<string>, registries: array{composer: string, npm: string}} $config */
        $config = config('builder.verification.packages');

        if (! $config['enabled']) {
            return;
        }

        $packages = rescue(fn () => PackagePolicy::inPatch(
            $featureRequest->patch,
            $config['allowed'],
            $config['licenses'],
            $config['registries'],
            fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false),
        ));

        $this->keepEvidence('packages', $packages);
    }

    /**
     * Read the emails and text messages the change adds, while the change
     * is still in the workspace (§12). It is kept as evidence; the owner
     * approves each one before the change is kept.
     */
    protected function readMessages(WorkspaceDriver $driver, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.messages.enabled')) {
            return;
        }

        $messages = rescue(fn () => NewMessages::inPatch(
            $featureRequest->patch,
            fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false),
        ), []);

        $this->keepEvidence('messages', $messages === [] ? null : $messages);
    }

    /**
     * Make small mistakes on purpose in the change's new code, one at a
     * time, and run the tests that run each changed line. A mistake no test
     * notices marks behaviour no test pins down, even when the tests fail
     * without the change. Like the other measurements this never changes
     * the checks' result; the reviewer and the owner read it. Each file is
     * put back as it was, and a mistake that does not parse is not tried.
     */
    protected function observeMutants(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, max: int, budget_seconds: int, command: list<string>, timeout: int, report: string} $config */
        $config = config('builder.verification.mutants');
        $map = $this->testMap;

        if (! $config['enabled'] || $map === null || $map->isEmpty() || array_intersect(array_keys($this->touched), self::PACKAGE_FILES) !== []) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $featureRequest, $config, $map) {
            $id = (string) $workspace->driver_id;
            $mutants = Mutants::choose($featureRequest->patch, fn (string $file, int $line) => $map->testsRunningLines($file, [$line]) !== null, $config['max']);
            $until = microtime(true) + $config['budget_seconds'];
            $tried = 0;
            $survived = [];

            foreach ($mutants as $mutant) {
                if (microtime(true) > $until) {
                    break;
                }

                $original = $driver->readFile($id, $mutant['file']);
                $lines = explode("\n", $original);

                if (($lines[$mutant['line'] - 1] ?? null) !== $mutant['was']) {
                    continue;
                }

                $lines[$mutant['line'] - 1] = $mutant['now'];
                $driver->writeFile($id, $mutant['file'], implode("\n", $lines));

                try {
                    if ($this->outcome($runWorkspaceCommand->handle($workspace, ['php', '-l', $mutant['file']], 30)) !== self::OUTCOME_PASSED) {
                        continue;
                    }

                    $files = array_values(array_unique(array_map(fn (int $test) => (string) ($map->tests[$test]['file'] ?? ''), $map->testsRunningLines($mutant['file'], [$mutant['line']]) ?? [])));
                    $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['report']], 30);
                    $command = $runWorkspaceCommand->handle($workspace, [...$config['command'], ...array_filter($files)], $config['timeout']);

                    if ($command->lost) {
                        throw new CommandLost($command->error_output);
                    }

                    $report = TestReport::fromJunit((string) rescue(fn () => $driver->readFile($id, $config['report']), '', report: false));

                    // Nothing ran, or it ran out of time: it says nothing.
                    if ($report === [] || $command->timed_out) {
                        continue;
                    }

                    $tried++;

                    if ($this->outcome($command) === self::OUTCOME_PASSED && ! in_array(TestReport::FAILED, array_column($report, 'outcome'), true)) {
                        $survived[] = [...$mutant, 'was' => Str::limit(trim($mutant['was']), 200), 'now' => Str::limit(trim($mutant['now']), 200)];
                    }
                } finally {
                    $driver->writeFile($id, $mutant['file'], $original);
                }
            }

            if ($tried > 0) {
                $this->keepEvidence('mutants', ['tried' => $tried, 'caught' => $tried - count($survived), 'survived' => $survived]);
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
            // An exception case counts as tested only when its test saw
            // the app refuse, so each new test's refusals are kept.
            $this->keepEvidence('refusals', TestRefusals::measure($this->requests, $featureRequest->patch));

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
     * Find what the change's code saved or sent while Laravel was checking
     * who may act, checking what was sent, or building the answer
     * (direction 33). The recorder names the phase of each thing a request
     * did, so this reads the requests recorded in the coverage run and
     * runs nothing. Like the traces, it never changes the checks' result.
     */
    protected function observeBoundaries(FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.boundaries.enabled')) {
            return;
        }

        rescue(fn () => $this->keepEvidence('boundaries', AppBoundaries::withRead(
            AppBoundaries::measure(
                $this->requests,
                $featureRequest->patch,
                array_column($this->evidence['routes']['added'] ?? [], 'route'),
                config('builder.verification.boundaries.phases'),
            ),
            $this->boundaryCode,
            config('builder.verification.boundaries.phases'),
        )));
    }

    /**
     * Find calls the change's code makes to an outside service from outside
     * the areas the rest of the app calls it from (direction 33). The areas
     * are the project's notes before the change, so a change cannot move
     * an area's paths to make room for its own call. It runs nothing and
     * never changes the checks' result.
     */
    protected function observeContainment(FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.boundaries.enabled') || $this->requests === []) {
            return;
        }

        rescue(function () use ($featureRequest) {
            $context = app(ReadProjectContext::class)->current($featureRequest->project);
            $names = array_map(fn (Capability $capability) => $capability->name, $context->capabilities);

            $this->keepEvidence('containment', AppContainment::measure(
                $this->requests,
                $featureRequest->patch,
                fn (string $path) => array_map(fn (string $key) => $names[$key] ?? $key, $context->claiming($path)),
            ));
        });
    }

    /**
     * Count the work per request in each area of the app, and find the
     * areas where it grew past the ceiling set when the app was last kept
     * (direction 33, a drift measure). The areas are the project's notes
     * before the change. It runs nothing and never changes the checks'
     * result.
     */
    protected function observeDrift(FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.drift.enabled') || $this->requests === []) {
            return;
        }

        rescue(function () use ($featureRequest) {
            $context = app(ReadProjectContext::class)->current($featureRequest->project);
            $names = array_map(fn (Capability $capability) => $capability->name, $context->capabilities);
            $measured = AppDrift::measure($this->requests, $context->claiming(...), config()->integer('builder.verification.drift.least'));

            if ($measured === []) {
                return;
            }

            $grown = AppDrift::grown($measured, $featureRequest->project->drift_ceilings ?? [], config()->float('builder.verification.drift.tolerance'), config()->float('builder.verification.drift.strict'));

            $this->keepEvidence('drift', [
                'areas' => $measured,
                'findings' => array_map(fn (array $finding) => [...$finding, 'name' => $names[$finding['area']] ?? $finding['area']], $grown),
            ]);
        });
    }

    /**
     * Find where the app keeps its saves and its sends, and the change's
     * new code that does that work straight from a controller or a Livewire
     * component instead (direction 33). It runs nothing and never changes
     * the checks' result.
     */
    protected function observeConventions(FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.conventions.enabled') || $this->requests === []) {
            return;
        }

        rescue(fn () => $this->keepEvidence('conventions', AppConventions::measure(
            $this->requests,
            $featureRequest->patch,
            config()->integer('builder.verification.conventions.least'),
            config()->float('builder.verification.conventions.share'),
        )));
    }

    /**
     * Find the calls from one area of the app into another that only the
     * change's code makes (direction 33). The areas are the project's
     * notes before the change. It runs nothing and never changes the
     * checks' result.
     */
    protected function observeCoupling(FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.coupling.enabled') || $this->requests === []) {
            return;
        }

        rescue(function () use ($featureRequest) {
            $context = app(ReadProjectContext::class)->current($featureRequest->project);
            $names = array_map(fn (Capability $capability) => $capability->name, $context->capabilities);

            $this->keepEvidence('coupling', AppCoupling::measure(
                $this->requests,
                $featureRequest->patch,
                fn (string $path) => array_map(fn (string $key) => $names[$key] ?? $key, $context->claiming($path)),
            ));
        });
    }

    /**
     * Read the PHP files the change touched, as it leaves them, for calls
     * that break a boundary rule (direction 33). This covers what the
     * recording cannot: code no test runs, and the app's start. It runs
     * before anything takes the change out of the workspace.
     */
    protected function readBoundaryCode(WorkspaceDriver $driver, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        if (! config('builder.verification.boundaries.enabled')) {
            return;
        }

        $this->boundaryCode = rescue(fn () => BoundaryCode::inPatch(
            $featureRequest->patch,
            fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false),
        ), ['read' => [], 'before' => []]);
    }

    /**
     * Cause one failure at a time where the change's code sends or saves,
     * and keep what the app left behind (direction 32). The places come
     * from the requests recorded in the coverage run. Each place runs its
     * one test again with one thing made to fail: an email, an outside
     * call or a save. A job the sync queue ran is made to run twice.
     * Places on a line or a route the recording already has a finding
     * about are tried before the rest.
     *
     * Like the screen check this never changes the checks' result. It
     * stops starting new places when its time is used up, so a change
     * with many places does not hold up the owner.
     */
    protected function observeFaults(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, points: int, seconds: int, command: list<string>, timeout: int, report: string} $config */
        $config = config('builder.verification.faults');

        if (! $config['enabled'] || $this->requests === []) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $featureRequest, $config) {
            // The routes the change added are not known yet, so only what
            // its own lines did is suspected here.
            $points = AppFaults::points($this->requests, $featureRequest->patch, [
                ...(AppTraces::measure($this->requests, $featureRequest->patch)['findings'] ?? []),
                ...(config('builder.verification.boundaries.enabled')
                    ? AppBoundaries::measure($this->requests, $featureRequest->patch, phases: config('builder.verification.boundaries.phases'))['findings'] ?? []
                    : []),
            ]);
            $until = now()->addSeconds($config['seconds']);
            $runs = [];

            foreach (array_slice($points, 0, $config['points']) as $position => $point) {
                if (now()->greaterThan($until)) {
                    break;
                }

                $command = $runWorkspaceCommand->handle(
                    $workspace,
                    [...$config['command'], $point['filter']],
                    $config['timeout'],
                    ['TRACE_RECORDER_FAULT' => (string) json_encode($point['fault'])],
                );

                // The workspace is gone: the places left are not tried.
                if ($command->lost) {
                    break;
                }

                $runs[$position] = $this->outcome($command) === self::OUTCOME_PASSED
                    ? AppTraces::parse((string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $config['report']), '', report: false))
                    : [];
            }

            $this->keepEvidence('faults', AppFaults::measure($points, $runs, $featureRequest->patch));
        });
    }

    /**
     * Try who may do what with the records the plan described, through the
     * app's own routes, and add the result as a check. Return false only
     * when a refused actor got through; a probe that could not run proves
     * nothing either way.
     */
    protected function probeAccess(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): bool
    {
        /** @var array{enabled: bool, probes: int, test: string, models: string, routes: array{command: list<string>, report: string}, command: list<string>, timeout: int, report: string, swaps: array{enabled: bool, limit: int, rows: int, bindings: string, test: string, command: list<string>, report: string}} $config */
        $config = config('builder.verification.access');

        if (! $config['enabled']) {
            return true;
        }

        try {
            $own = $this->plannedRecords($featureRequest);
            $names = array_column($own, 'name');

            // The rules earlier kept changes stated stay tests: a change
            // that breaks one is caught even where it did not touch the
            // record. The change's own records come first, so the limit
            // never cuts them.
            $records = array_values(array_filter(
                [...$own, ...array_filter($this->keptRecords($featureRequest), fn (array $record) => ! in_array($record['name'], $names, true))],
                fn (array $record) => ($record['access'] ?? null) !== null,
            ));

            // Records the app already had answer to its own policy, on the
            // routes of the controllers the change touched.
            $controllers = $this->touchedControllers();

            if ($records === [] && $controllers === []) {
                return true;
            }

            $read = fn (string $path) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), '', report: false);
            $routes = $runWorkspaceCommand->handle($workspace, $config['routes']['command'], 60);

            if ($this->outcome($routes) !== self::OUTCOME_PASSED) {
                return true;
            }

            $planned = AccessProbes::plan($records, $read($config['routes']['report']), $config['probes']);
            // A record a later change took out has no route; only the
            // change's own are worth naming.
            $planned['unmatched'] = array_values(array_intersect($planned['unmatched'], $names));

            if ($controllers !== []) {
                // Crossing a team is tried first; the policy is not asked there.
                $driver->writeFile((string) $workspace->driver_id, $config['models'], AccessProbes::introspection());
                $teams = AccessProbes::teams($runWorkspaceCommand->handle($workspace, ['php', $config['models']], 60)->output);
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['models']], 30);
                $planned['probes'] = [...$planned['probes'], ...($teams === null ? [] : AccessProbes::forTenants($teams, $read($config['routes']['report']), $controllers, $config['probes'] - count($planned['probes'])))];
                $tried = array_map(fn (array $probe) => "{$probe['actor']} {$probe['method']} {$probe['uri']}", $planned['probes']);

                $policies = $runWorkspaceCommand->handle($workspace, ['find', 'app/Policies', '-name', '*Policy.php', '-type', 'f'], 30);
                $models = array_values(array_diff(
                    preg_match_all('#app/Policies/(\w+)Policy\.php#', $policies->output, $found) > 0 ? $found[1] : [],
                    array_column($records, 'name'),
                ));
                $planned['probes'] = [...$planned['probes'], ...array_filter(
                    AccessProbes::fromPolicies($models, $read($config['routes']['report']), $controllers, $config['probes'] - count($planned['probes'])),
                    fn (array $probe) => ! in_array("{$probe['actor']} {$probe['method']} {$probe['uri']}", $tried, true),
                )];
            }

            // Someone else's records in the address, on the routes of the
            // controllers the change touched. A route a person outside the
            // team already tries is not tried again with one record.
            $found = null;
            $swaps = ['probes' => [], 'skipped' => 0];

            if ($controllers !== [] && $config['swaps']['enabled']) {
                $driver->writeFile((string) $workspace->driver_id, $config['swaps']['bindings'], SwapProbes::introspection());
                $found = SwapProbes::found($runWorkspaceCommand->handle($workspace, ['php', $config['swaps']['bindings']], 60)->output);
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['swaps']['bindings']], 30);
                $strangers = array_map(fn (array $probe) => "{$probe['method']} {$probe['uri']}", array_filter($planned['probes'], fn (array $probe) => $probe['actor'] === AccessProbes::STRANGER));
                $swaps = $found === null ? $swaps : SwapProbes::plan($found, $controllers, array_values($strangers), $config['swaps']['limit']);
            }

            if ($planned['probes'] === [] && $swaps['probes'] === []) {
                return true;
            }

            $durationMs = 0;
            $measured = ['tried' => 0, 'refused' => 0, 'findings' => [], 'untried' => 0];

            if ($planned['probes'] !== []) {
                $driver->writeFile((string) $workspace->driver_id, $config['test'], AccessProbes::test($planned['probes'], $config['report']));
                $command = $runWorkspaceCommand->handle($workspace, [...$config['command'], $config['test']], $config['timeout']);
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['test']], 30);

                if ($command->lost) {
                    throw new CommandLost($command->error_output);
                }

                $durationMs += (int) $command->duration_ms;
                $measured = AccessProbes::measure($planned['probes'], AccessProbes::parse($read($config['report'])));
            }

            $swapped = ['tried' => 0, 'refused' => 0, 'shared' => 0, 'findings' => [], 'untried' => 0];
            $lists = ['tried' => 0, 'findings' => [], 'broke' => [], 'untried' => 0];

            if ($swaps['probes'] !== [] && $found !== null) {
                $driver->writeFile((string) $workspace->driver_id, $config['swaps']['test'], SwapProbes::test($swaps['probes'], $found, $config['swaps']['report'], $config['swaps']['rows']));
                $command = $runWorkspaceCommand->handle($workspace, [...$config['swaps']['command'], $config['swaps']['test']], $config['timeout']);
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['swaps']['test']], 30);

                if ($command->lost) {
                    throw new CommandLost($command->error_output);
                }

                $durationMs += (int) $command->duration_ms;
                $observed = SwapProbes::parse($read($config['swaps']['report']));
                $swapped = SwapProbes::measure($swaps['probes'], $observed);
                $lists = SwapProbes::measureLists($swaps['probes'], $observed, $found, InputProbes::changed(array_map(fn (FeatureRequest $request) => $request->patch, $featureRequest->lineage())));
            }

            // Only lists the change loaded whole stop it.
            $listed = array_filter($lists['findings'], fn (array $finding) => ! $finding['existing']) === [];

            if ($lists['tried'] > 0 || $lists['broke'] !== []) {
                $this->addResult(__('Long lists show a page at a time'), 'checks', $listed ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, output: SwapProbes::describeLists($lists));
            }

            if ($measured['tried'] === 0 && $swapped['tried'] === 0) {
                return $listed;
            }

            // Which rules of earlier kept changes this change broke, for the
            // evolution measure: a rule the app forgot is the cost it tracks.
            $this->keepEvidence('earlier_rules', array_values(array_unique(array_map(
                fn (array $finding) => "{$finding['record']} {$finding['action']}",
                array_filter($measured['findings'], fn (array $finding) => ! in_array($finding['record'], $names, true) && $finding['rule'] !== 'policy' && ! str_starts_with($finding['rule'], 'tenant:')),
            ))));

            $passed = $measured['findings'] === [] && $swapped['findings'] === [];
            $output = implode("\n", array_filter([
                $measured['tried'] > 0 ? AccessProbes::describe($measured, $planned['unmatched']) : null,
                $swapped['tried'] > 0 ? SwapProbes::describe($swapped, $swaps['skipped']) : null,
            ]));
            $this->addResult(__('Who may see and change records'), 'checks', $passed ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, durationMs: $durationMs, output: $output);

            return $passed && $listed;
        } catch (CommandLost $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /**
     * Try who may do what inside a team, with the change and on the
     * starting commit, and add the result as a check. Return false only
     * when someone outside a team gained a thing; what a role gained or
     * lost is kept for the reviewer and the owner. A probe that could not
     * run proves nothing either way, and says why, so the owner sees the gap.
     */
    protected function probeRoles(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): bool
    {
        /** @var array{enabled: bool, probes: int, test: string, models: string, command: list<string>, timeout: int, report: string} $config */
        $config = config('builder.verification.roles');

        if (! $config['enabled'] || ! collect($this->touched)->keys()->contains(fn (string $path) => str_ends_with($path, '.php'))) {
            return true;
        }

        $skipped = function (string $why) {
            /** @var 'failed'|'empty'|'before'|'unrun'|'ours' $why */
            $this->addResult(__(RoleProbes::CHECK), 'checks', self::OUTCOME_SKIPPED, output: RoleProbes::skipped($why));

            return true;
        };

        try {
            $read = fn (string $path) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), '', report: false);
            $output = '';
            $find = function () use ($driver, $runWorkspaceCommand, $workspace, $config, &$output) {
                $driver->writeFile((string) $workspace->driver_id, $config['models'], RoleProbes::introspection());
                $output = $runWorkspaceCommand->handle($workspace, ['php', $config['models']], 60)->output;
                $found = RoleProbes::found($output);
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['models']], 30);

                return $found;
            };
            $send = function (array $probes, array $tenants) use ($driver, $runWorkspaceCommand, $workspace, $config, $read) {
                /** @var list<Probe> $probes */
                /** @var list<Tenant> $tenants */
                $driver->writeFile((string) $workspace->driver_id, $config['test'], RoleProbes::test($probes, $tenants, $config['report']));
                $command = $runWorkspaceCommand->handle($workspace, [...$config['command'], $config['test']], $config['timeout']);
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['test']], 30);

                if ($command->lost) {
                    throw new CommandLost($command->error_output);
                }

                return [RoleProbes::parse($read($config['report'])), $command->duration_ms];
            };

            $found = $find();
            $probes = $found === null ? [] : RoleProbes::plan($found, $config['probes']);

            // The app's tests passed, so it starts: output that is not the
            // script's means the script broke, which is ours to fix.
            if ($found === null) {
                report(new RuntimeException('The role probe script printed no teams: '.Str::limit($output, 500)));

                return $skipped('ours');
            }

            // An app with no team roles has nothing to try; one whose Spatie
            // roles could not be read has a gap.
            if ($probes === []) {
                return $found['unread'] === null ? true : $skipped($found['unread']);
            }

            [$after, $duration] = $send($probes, $found['tenants']);

            // The same requests on the starting commit, so only what the
            // change did is told.
            $lineage = $featureRequest->lineage();
            $undone = [];

            foreach (array_reverse(array_keys($lineage)) as $position) {
                if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position, reverse: true)) {
                    break;
                }

                $undone[] = $position;
            }

            $before = [];
            $routesBefore = null;

            if (count($undone) === count($lineage)) {
                $start = $find();
                $routesBefore = $start === null ? null : array_map(RoleProbes::label(...), $start['routes']);
                [$before] = $start === null ? [[]] : $send($probes, $found['tenants']);
            }

            foreach (array_reverse($undone) as $position) {
                if (! $this->applyPatch($driver, $runWorkspaceCommand, $workspace, $lineage, $position)) {
                    throw new RuntimeException('The change could not be put back after its roles were tried on the starting commit.');
                }
            }

            $runWorkspaceCommand->handle($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30);

            if ($routesBefore === null) {
                return $skipped('before');
            }

            $measured = RoleProbes::measure($probes, $after, $before, $routesBefore);

            if ($measured['tried'] === 0) {
                return $skipped('unrun');
            }

            $this->keepEvidence('roles', $measured);
            $passed = $measured['findings'] === [];
            $this->addResult(__(RoleProbes::CHECK), 'checks', $passed ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, durationMs: $duration, output: RoleProbes::describe($measured));

            return $passed;
        } catch (CommandLost $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $skipped('ours');
        }
    }

    /**
     * Send each form that adds a record the change works on twice, and add
     * the result as a check. Return false only when the database refused
     * the second send as a duplicate and the page broke; a probe that
     * could not run proves nothing either way.
     */
    protected function replayForms(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): bool
    {
        /** @var array{enabled: bool, probes: int, test: string, command: list<string>, timeout: int, report: string} $config */
        $config = config('builder.verification.replay');
        /** @var array{command: list<string>, report: string} $routes */
        $routes = config('builder.verification.access.routes');

        if (! $config['enabled']) {
            return true;
        }

        try {
            $records = $this->plannedRecords($featureRequest);
            $controllers = $this->touchedControllers();

            if ($records === [] && $controllers === []) {
                return true;
            }

            $read = fn (string $path) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), '', report: false);

            if ($this->outcome($runWorkspaceCommand->handle($workspace, $routes['command'], 60)) !== self::OUTCOME_PASSED) {
                return true;
            }

            $models = $controllers === [] ? [] : (preg_match_all('#app/Models/(\w+)\.php#', $runWorkspaceCommand->handle($workspace, ['find', 'app/Models', '-maxdepth', '1', '-name', '*.php', '-type', 'f'], 30)->output, $found) > 0 ? $found[1] : []);
            $probes = ReplayProbes::plan($records, $models, $read($routes['report']), $controllers, $config['probes']);

            if ($probes === []) {
                return true;
            }

            $driver->writeFile((string) $workspace->driver_id, $config['test'], ReplayProbes::test($probes, $config['report']));
            $command = $runWorkspaceCommand->handle($workspace, [...$config['command'], $config['test']], $config['timeout']);
            $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['test']], 30);

            if ($command->lost) {
                throw new CommandLost($command->error_output);
            }

            $measured = ReplayProbes::measure($probes, ReplayProbes::parse($read($config['report'])));

            if ($measured['tried'] === 0) {
                return true;
            }

            $passed = $measured['findings'] === [];
            $this->addResult(__('Sending a form twice'), 'checks', $passed ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, durationMs: $command->duration_ms, output: ReplayProbes::describe($measured));

            return $passed;
        } catch (CommandLost $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /**
     * Send wrong values to each form on a controller the change touched,
     * and add the result as a check. Return false only for a finding on a
     * field or rule the change added; a form that could not be filled in
     * or was turned down whole proves nothing either way.
     */
    protected function probeInputs(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): bool
    {
        /** @var array{enabled: bool, probes: int, rules_test: string, test: string, command: list<string>, timeout: int, rules_report: string, report: string} $config */
        $config = config('builder.verification.inputs');
        /** @var array{command: list<string>, report: string} $routes */
        $routes = config('builder.verification.access.routes');
        $controllers = $this->touchedControllers();

        if (! $config['enabled'] || $controllers === []) {
            return true;
        }

        try {
            $read = fn (string $path) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), '', report: false);
            $run = function (string $path, string $test, string $report) use ($driver, $runWorkspaceCommand, $workspace, $config): WorkspaceCommand {
                $driver->writeFile((string) $workspace->driver_id, $path, $test);
                $command = $runWorkspaceCommand->handle($workspace, [...$config['command'], $path, $report], $config['timeout']);
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $path], 30);

                if ($command->lost) {
                    throw new CommandLost($command->error_output);
                }

                return $command;
            };

            if ($this->outcome($runWorkspaceCommand->handle($workspace, $routes['command'], 60)) !== self::OUTCOME_PASSED) {
                return true;
            }

            $found = InputProbes::routes($read($routes['report']), $controllers);

            if ($found === []) {
                return true;
            }

            $first = $run($config['rules_test'], InputProbes::rulesTest($found, $config['rules_report']), $config['rules_report']);
            $rules = InputProbes::rules($read($config['rules_report']));
            $planned = InputProbes::plan($found, $rules, $config['probes'], InputProbes::examples($this->plannedRecords($featureRequest)));

            if ($planned['baselines'] === []) {
                return true;
            }

            $second = $run($config['test'], InputProbes::test($found, $planned, $config['report']), $config['report']);
            $patches = array_map(fn (FeatureRequest $request) => $request->patch, $featureRequest->lineage());
            $measured = InputProbes::measure($found, $rules, $planned, InputProbes::parse($read($config['report'])), InputProbes::changed($patches));

            if ($measured['tried'] === 0) {
                return true;
            }

            $passed = $measured['findings'] === [];
            $this->addResult(__('Forms turn down wrong values'), 'checks', $passed ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, durationMs: $first->duration_ms + $second->duration_ms, output: InputProbes::describe($found, $measured));

            return $passed;
        } catch (CommandLost $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /**
     * Get the records the plans of the change and its earlier rounds
     * described, the latest of each name.
     *
     * @return list<Record>
     */
    protected function plannedRecords(FeatureRequest $featureRequest): array
    {
        return array_values(collect($featureRequest->lineage())
            ->flatMap(fn (FeatureRequest $request) => $request->latestRun?->plan === null ? [] : Plan::fromArray($request->latestRun->plan)->dataShape)
            ->keyBy('name')
            ->all());
    }

    /**
     * Get the records the plans of the project's kept changes described,
     * the latest of each name: the main app's, and the open idea's own
     * when the change was made in one. Only changes whose access probes
     * passed count.
     *
     * @return list<Record>
     */
    protected function keptRecords(FeatureRequest $featureRequest): array
    {
        return array_values(FeatureRequest::query()
            ->whereBelongsTo($featureRequest->project)
            ->whereKeyNot($featureRequest->getKey())
            ->whereNotNull('commit_sha')
            ->whereNull('reverted_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('experiment_id')
                ->orWhereHas('experiment', fn (Builder $query) => $query->where('status', ExperimentStatus::Merged))
                ->when($featureRequest->experiment_id !== null, fn (Builder $query) => $query->orWhere('experiment_id', $featureRequest->experiment_id)))
            ->with(['latestRun', 'verifications'])
            ->oldest('id')
            ->get()
            // Only rules the probes proved when their change was kept, so a
            // problem the app already had is not held against this change.
            ->filter(fn (FeatureRequest $request) => $request->verifications->contains(fn (Verification $verification) => collect($verification->results)->contains(fn (array $result) => $result['name'] === __('Who may see and change records') && $result['outcome'] === self::OUTCOME_PASSED)))
            ->flatMap(fn (FeatureRequest $request) => $request->latestRun?->plan === null ? [] : rescue(fn () => Plan::fromArray($request->latestRun->plan)->dataShape, [], report: false))
            ->keyBy('name')
            ->all());
    }

    /**
     * Get the controllers the change added or changed, by class name.
     *
     * @return list<string>
     */
    protected function touchedControllers(): array
    {
        return array_values(collect($this->touched)
            ->reject(fn (bool $deleted, string $path) => $deleted || ! preg_match('#^app/Http/Controllers/(.+)\.php$#', $path))
            ->keys()
            ->map(fn (string $path) => 'App\\Http\\Controllers\\'.str_replace('/', '\\', substr($path, 21, -4)))
            ->all());
    }

    /**
     * When the change's code works with dates, run its own tests on an
     * ordinary day and at moments where date code often breaks, and add
     * the result as a check. Return false only when a test that passed on
     * the ordinary day failed at a moment twice; a run that could not
     * start proves nothing either way.
     */
    protected function shiftTime(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): bool
    {
        /** @var array{enabled: bool, bootstrap: string, command: list<string>, timeout: int, report: string} $config */
        $config = config('builder.verification.time');
        $patches = array_map(fn (FeatureRequest $request) => $request->patch, $featureRequest->lineage());
        $tests = array_keys(NewTests::files($patches));

        if (! $config['enabled'] || $tests === [] || ! array_any($patches, fn (?string $patch) => TimeShifts::touchesDates($patch))) {
            return true;
        }

        try {
            $read = fn (string $path) => (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), '', report: false);
            $durationMs = 0;
            $run = function (string $at, array $files) use ($runWorkspaceCommand, $workspace, $config, $read, &$durationMs) {
                $command = $runWorkspaceCommand->handle($workspace, array_values([...$config['command'], $at, $config['report'], ...$files]), $config['timeout']);

                if ($command->lost) {
                    throw new CommandLost($command->error_output);
                }

                $durationMs += $command->duration_ms;

                return TestReport::fromJunit($read($config['report']));
            };

            $driver->writeFile((string) $workspace->driver_id, $config['bootstrap'], TimeShifts::bootstrap());
            $control = $run(TimeShifts::CONTROL, $tests);

            if (! collect($control)->contains('outcome', TestReport::PASSED)) {
                return true;
            }

            $findings = TimeShifts::failing($control, array_map(fn (string $at) => $run($at, $tests), TimeShifts::MOMENTS));

            // Once more, only where a test failed, so a test that fails now
            // and then for another reason does not send the change back.
            $again = [];

            foreach (array_unique(array_column($findings, 'moment')) as $moment) {
                $files = array_values(array_unique(array_column(array_filter($findings, fn (array $finding) => $finding['moment'] === $moment), 'file')));
                $again[$moment] = $run(TimeShifts::MOMENTS[$moment], array_values(array_filter($tests, fn (string $path) => array_any($files, fn (string $file) => str_ends_with($file, '/'.$path) || $file === $path))));
            }

            $findings = TimeShifts::confirmed($findings, $again);

            $passed = $findings === [];
            $this->addResult(__('Dates at the edges'), 'checks', $passed ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, durationMs: $durationMs, output: TimeShifts::describe($findings, count($control)));

            return $passed;
        } catch (CommandLost $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        } finally {
            rescue(fn () => $runWorkspaceCommand->handle($workspace, ['rm', '-f', $config['bootstrap']], 30), report: false);
        }
    }

    /**
     * Run the change's own test files with Laravel's strict model modes on,
     * and fail the change on a value it silently does not save or an
     * attribute it reads that its model lacks, where the change's own
     * code or model did it. A change with no tests, or no PHP of its own,
     * is not run.
     */
    protected function checkStrictModels(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): bool
    {
        /** @var array{enabled: bool, command: list<string>, timeout: int} $config */
        $config = config('builder.verification.strict');
        $tests = array_keys(NewTests::files(array_map(fn (FeatureRequest $request) => $request->patch, $featureRequest->lineage())));
        $code = array_values(array_filter(array_keys($this->touched), fn (string $path) => str_ends_with($path, '.php') && ! str_starts_with($path, 'tests/')));

        if (! $config['enabled'] || $tests === [] || $code === []) {
            return true;
        }

        $command = $runWorkspaceCommand->handle($workspace, [...$config['command'], ...$tests], $config['timeout']);

        if ($command->lost) {
            throw new CommandLost($command->error_output);
        }

        if ($command->exit_code !== 0) {
            return true;
        }

        $violations = StrictModels::theChanges(StrictModels::parse($command->output), array_keys($this->touched));
        $passed = array_filter($violations, StrictModels::blocks(...)) === [];

        if ($violations !== []) {
            $this->keepEvidence('strict', $violations);
        }

        $this->addResult(StrictModels::CHECK, 'checks', $passed ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, durationMs: $command->duration_ms, output: StrictModels::describe($violations));

        return $passed;
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
     * Read the package manifests as they are in the workspace now.
     *
     * @return array<string, string|null>
     */
    protected function readManifests(WorkspaceDriver $driver, Workspace $workspace): array
    {
        $manifests = [];

        foreach (ProtectedInputs::MANIFESTS as $manifest) {
            $manifests[$manifest] = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $manifest), null, report: false);
        }

        return $manifests;
    }

    /**
     * Refuse a change that edits a protected file or a manifest's scripts,
     * before anything runs: the checks would no longer be ours. The result
     * names each file, so the next attempt knows what to leave alone.
     *
     * @param  array<string, string|null>  $before
     */
    protected function guardProtectedInputs(WorkspaceDriver $driver, Workspace $workspace, array $before): bool
    {
        $files = ProtectedInputs::touched(array_keys($this->touched), config('builder.construction.protected_paths', []));

        foreach ($this->readManifests($driver, $workspace) as $manifest => $after) {
            if (ProtectedInputs::scriptsChanged($before[$manifest] ?? null, $after)) {
                $files[] = __('the scripts in :file', ['file' => $manifest]);
            }
        }

        if ($files === []) {
            return true;
        }

        $this->addResult(__('Files the checks depend on'), 'apply', self::OUTCOME_FAILED, output: __('The change edits files that decide how the checks run: :files. Leave them as they were; changing them is a decision for a person.', ['files' => implode(', ', $files)]));

        return false;
    }

    /**
     * Get the configured setup commands or checks.
     *
     * @return list<array{name: string, command: list<string>, timeout: int, report?: string, files?: list<string>, needs?: string}>
     */
    protected function configuredSteps(string $stage): array
    {
        /** @var list<array{name: string, command: list<string>, timeout: int, report?: string, files?: list<string>, needs?: string}> $steps */
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
    protected function finish(VerificationStatus $status, ?string $error = null, bool $interrupted = false, ?ChecksStoppedBecause $stoppedBecause = null): void
    {
        $this->verification->update([
            'status' => $status,
            'results' => $this->results,
            'error' => $error ?? $stoppedBecause?->message(),
            'stopped_because' => $stoppedBecause,
            'interrupted' => $interrupted,
            'finished_at' => now(),
        ]);

        app(CompleteRunVerification::class)->handle($this->verification);
        // Design edits the owner asked to keep join the app once cleared.
        app(CommitDesignEdits::class)->handle($this->verification);
    }
}
