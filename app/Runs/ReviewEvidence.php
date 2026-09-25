<?php

namespace App\Runs;

/**
 * What a reviewer judges a change on, assembled by the platform: the saved
 * plan, the change read back from the workspace, the tests it weakens and the
 * independent verification results. The coder's own account is left out.
 */
final readonly class ReviewEvidence
{
    /**
     * @param  list<array{path: string, deleted: bool, removed_assertions: int}>  $weakenedTests
     * @param  list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>  $verificationResults
     */
    public function __construct(
        public string $request,
        public Plan $plan,
        public string $patch,
        public array $weakenedTests,
        public string $verificationStatus,
        public array $verificationResults,
    ) {}
}
