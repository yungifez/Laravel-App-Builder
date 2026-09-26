<?php

namespace App\Runs;

use App\Context\ChangeClassification;

/**
 * What a reviewer judges a change on, assembled by the platform: the saved
 * plan, the change read back from the workspace, the tests it weakens, the
 * independent verification results, the project context the coder was given
 * and where the change landed by area. The coder's own account is left out.
 */
final readonly class ReviewEvidence
{
    /**
     * @param  list<array{path: string, deleted: bool, removed_assertions: int}>  $weakenedTests
     * @param  list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>  $verificationResults
     * @param  array<string, string>  $areaNames  Area names, keyed by area
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
    ) {}
}
