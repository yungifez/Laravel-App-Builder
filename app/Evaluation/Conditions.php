<?php

namespace App\Evaluation;

use App\Ai\Agents\GenericReviewer;
use App\Enums\ModelRole;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Verification;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

/**
 * Runs the three verification conditions on one frozen code snapshot. The
 * project's checks run once; every condition judges those same results.
 *
 * - v1, tests only: the checks.
 * - v2, generic review: the checks plus a general-purpose reviewer given the
 *   request, the project's requirements as written, the check results and
 *   the diff.
 * - v3, behaviour-aware review: the checks plus the pipeline's reviewer and
 *   preservation evidence, from the plan the pipeline derived for the task.
 *
 * Each condition makes at most one model call.
 */
class Conditions
{
    public function __construct(protected PipelineHarness $harness) {}

    /**
     * @param  Run  $run  The pipeline's run for the task, whose plan and context v3 uses
     * @return array{checks: list<array<string, mixed>>, v1: array<string, mixed>, v2: array<string, mixed>, v3: array<string, mixed>}
     */
    public function run(Workbench $workbench, string $patch, string $request, string $requirements, Run $run): array
    {
        $checks = Evidence::checks($workbench);
        $results = Evidence::asVerificationResults($checks);
        $failing = array_values(array_filter($checks, fn (array $check) => $check['outcome'] !== 'passed'));
        $status = $failing === [] ? 'passed' : 'failed';

        $generic = Handoff::within(['condition' => 'v2'], fn () => $this->genericReview($request, $requirements, $results, $patch));
        $aware = Handoff::within(['condition' => 'v3'], fn () => $this->harness->review($run, $this->checked($run, $status, $results, $patch)));

        return [
            'checks' => $checks,
            'v1' => [
                'flagged' => $failing !== [],
                'failing_checks' => array_column($failing, 'name'),
                'failed_tests' => array_values(array_unique(array_merge(...array_column($failing, 'failed_tests') ?: [[]]))),
            ],
            'v2' => [
                'flagged' => $this->objects($generic),
                'review' => $generic,
            ],
            'v3' => [
                'flagged' => $this->objects($aware) || array_intersect(array_column($aware['preserved'], 'evidence'), ['regression_suspected', 'tests_failed']) !== [],
                'review' => $aware,
            ],
        ];
    }

    /**
     * Stand the checks in for a verification of the patch. Every condition
     * judges the same results, so v3 gets nothing more from running the app.
     *
     * @param  list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>  $results
     */
    protected function checked(Run $run, string $status, array $results, string $patch): Verification
    {
        $change = new FeatureRequest(['patch' => $patch]);
        $change->setRelation('project', $run->featureRequest->project);

        return (new Verification(['status' => $status, 'results' => $results, 'evidence' => []]))->setRelation('featureRequest', $change);
    }

    /**
     * Ask the generic reviewer.
     *
     * @param  list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>  $results
     * @return array{approved: bool, summary: string, findings: list<array{severity: string, summary: string, file: string|null}>}
     */
    protected function genericReview(string $request, string $requirements, array $results, string $patch): array
    {
        $response = GenericReviewer::make()->prompt(
            self::genericPrompt($request, $requirements, $results, $patch),
            provider: ModelRole::Reviewer->provider(),
            model: ModelRole::Reviewer->model(),
        );

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('The generic reviewer did not return structured output.');
        }

        $findings = [];

        foreach (is_array($response->structured['findings'] ?? null) ? $response->structured['findings'] : [] as $finding) {
            if (is_array($finding)) {
                $findings[] = [
                    'severity' => ($finding['severity'] ?? null) === 'blocking' ? 'blocking' : 'minor',
                    'summary' => is_string($finding['summary'] ?? null) ? $finding['summary'] : '',
                    'file' => is_string($finding['file'] ?? null) ? $finding['file'] : null,
                ];
            }
        }

        return [
            'approved' => ($response->structured['approved'] ?? false) === true,
            'summary' => is_string($response->structured['summary'] ?? null) ? $response->structured['summary'] : '',
            'findings' => $findings,
        ];
    }

    /**
     * Lay out the generic reviewer's evidence: the same check results and
     * diff the pipeline's reviewer gets, formatted the same way, with the
     * requirements as written instead of a plan.
     *
     * @param  list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>  $results
     */
    public static function genericPrompt(string $request, string $requirements, array $results, string $patch): string
    {
        $limit = (int) config('builder.construction.limits.review_diff_characters');
        $checks = array_map(
            fn (array $result) => "- [{$result['outcome']}] {$result['name']}".($result['outcome'] === 'passed' ? '' : "\n  ".str_replace("\n", "\n  ", mb_substr($result['output'], -1500))),
            $results,
        );

        return implode("\n\n", [
            "## Request\n\n{$request}",
            "## Project requirements\n\n{$requirements}",
            "## Checks\n\n".implode("\n", $checks),
            "## Diff\n\n```diff\n".(mb_strlen($patch) > $limit ? mb_substr($patch, 0, $limit)."\n… (diff cut at {$limit} characters; judge the remainder as unreviewed)" : $patch)."\n```",
        ]);
    }

    /**
     * Determine if a review objects to the change.
     *
     * @param  array<string, mixed>  $review
     */
    protected function objects(array $review): bool
    {
        $findings = is_array($review['findings'] ?? null) ? $review['findings'] : [];

        return ($review['approved'] ?? false) !== true
            || array_filter($findings, fn ($finding) => is_array($finding) && ($finding['severity'] ?? null) === 'blocking') !== [];
    }
}
