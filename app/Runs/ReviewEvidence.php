<?php

namespace App\Runs;

use App\Context\ChangeClassification;

/**
 * What a reviewer judges a change on, assembled by the platform: the saved
 * plan, the change read back from the workspace, the tests it weakens, the
 * independent verification results, what running the app with and without
 * the change showed, the project context the coder was given and where the
 * change landed by area. The coder's own account is left out.
 */
final readonly class ReviewEvidence
{
    /**
     * @param  list<array{path: string, deleted: bool, removed_assertions: int}>  $weakenedTests
     * @param  list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string, at_start?: string, new_problems?: list<string>}>  $verificationResults
     * @param  array<string, string>  $areaNames  Area names, keyed by area
     * @param  array{new_tests?: list<array{file: string, name: string, without_change: string}>, routes?: array{added?: list<array{route: string, middleware: list<string>}>, removed?: list<string>, changed?: list<array{route: string, lost: list<string>, gained: list<string>}>}, new_code?: array{lines: int, run: int, own_tests_only: int, unrun: array<string, list<int>>}, traces?: array{requests: int, reached: int, unseen: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, test: string|null}>, repeats: list<array{path: string, line: int, count: int, route: string}>}, faults?: array{points: int, run: int, missed: int, existing: int, findings: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string}>}}  $changeEvidence  What running the app with and without the change showed
     */
    public function __construct(
        public string $request,
        public Plan $plan,
        public string $patch,
        public array $weakenedTests,
        public string $verificationStatus,
        public array $verificationResults,
        public string $projectContext = '',
        public ChangeClassification $classification = new ChangeClassification,
        public array $areaNames = [],
        public array $changeEvidence = [],
    ) {}
}
