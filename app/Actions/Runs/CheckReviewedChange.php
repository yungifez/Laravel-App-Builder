<?php

namespace App\Actions\Runs;

use App\Actions\Context\AssessVerifyItems;
use App\Context\Capability;
use App\Features\InventedColours;
use App\Features\NewTests;
use App\Features\NodeInPhpTests;
use App\Features\OwnFormatChecks;
use App\Features\ScreenCheck;
use App\Features\UndescribedImages;
use App\Features\UnsafeCode;
use App\Models\Verification;
use App\Runs\Plan;
use App\Runs\Review;

/**
 * Add the platform's own checks of a reviewed change to its review: each
 * verify item needs a test that ran, a new test must fail without the
 * change, and the code scans and the measured pages must find nothing. No
 * model decides them, so
 * a finding blocks the change whatever the reviewer said. The run's review
 * stage and the evaluation both use it, so the evaluation blocks what ships.
 */
class CheckReviewedChange
{
    public function __construct(protected AssessVerifyItems $assessVerifyItems) {}

    /**
     * Check the change the verification checked. Findings are only added
     * when the coder can repair the change; the verify items are always
     * assessed.
     *
     * @return array{review: Review, verified: list<array{criterion: string, kind: string, case: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}>}
     */
    public function handle(Review $review, Plan $plan, Verification $verification, bool $canRepair): array
    {
        $patch = (string) $verification->featureRequest->patch;
        $verified = $this->assessVerifyItems->handle($plan, $review, $patch, $verification->results ?? [], $verification->evidence ?? []);

        if (! $canRepair) {
            return ['review' => $review, 'verified' => $verified];
        }

        if (config('builder.verification.require_verify_tests')) {
            $review = $review->withBlockingFindings($this->untested($verified, $patch, $verification->evidence['new_tests'] ?? []));
        }

        if (config('builder.verification.safety_scan')) {
            $review = $review->withBlockingFindings(array_map(UnsafeCode::finding(...), UnsafeCode::found($patch)));
        }

        if (config('builder.verification.design_scan')) {
            $review = $review->withBlockingFindings(array_map(InventedColours::finding(...), InventedColours::found($patch)));
            $review = $review->withBlockingFindings(array_map(UndescribedImages::finding(...), UndescribedImages::found($patch)));
        }

        // A format is decided once (§9): a check of its own on a formatted
        // field sends the change back.
        $review = $review->withBlockingFindings(array_map(OwnFormatChecks::finding(...), OwnFormatChecks::found($patch, $plan->dataShape)));

        if (config('builder.verification.test_scan')) {
            $review = $review->withBlockingFindings(array_map(NodeInPhpTests::finding(...), NodeInPhpTests::found($patch)));
        }

        return ['review' => $review, 'verified' => $verified];
    }

    /**
     * Block a change whose pages the verification measured cut off, too wide
     * or too small to tap at some width. The run's review stage adds these
     * after its gate, so they come last.
     */
    public function screens(Review $review, Verification $verification, bool $canRepair): Review
    {
        if (! $canRepair || ! config('builder.verification.screens.enabled')) {
            return $review;
        }

        return $review->withBlockingFindings(array_map(ScreenCheck::finding(...), ScreenCheck::found($verification->screens, $verification->featureRequest->patch)));
    }

    /**
     * Say what the change's tests do not show.
     *
     * @param  list<array{criterion: string, kind: string, case: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}>  $verified
     * @param  list<array{file: string, name: string, without_change: string}>  $measured  What the verification measured of the new tests
     * @return list<string>
     */
    protected function untested(array $verified, string $patch, array $measured): array
    {
        $findings = [];

        foreach ($verified as $item) {
            $finding = match ($item['evidence']) {
                'no_test' => __('No test in the change checks: :criterion', ['criterion' => $item['case']]),
                // What the recording saw, not what the test says: an
                // exception case is checked by a request the app refused.
                'no_request' => __('The test ":name" for ":case" sends your app nothing, so no refusal could be seen. Make it send the request, or run the command, that the app must refuse, and assert the refusal.', ['name' => $item['test_name'] ?? '', 'case' => $item['case']]),
                'not_refused' => __('The app let every request of the test ":name" through, but ":case" says it must refuse. Make the app refuse it, or make the test try that case.', ['name' => $item['test_name'] ?? '', 'case' => $item['case']]),
                'not_run_by_checks' => Capability::runBySuite((string) $item['test_file'])
                    ? __('The test ":name" for ":criterion" did not run in the test suite (:file). It is missing, skipped or named differently. Name a test that exists and runs.', [
                        'name' => $item['test_name'] ?? '',
                        'criterion' => $item['case'],
                        'file' => $item['test_file'],
                    ])
                    : __('The test for ":criterion" (:file) is not run by the test suite. Check it in a test under :paths.', [
                        'criterion' => $item['case'],
                        'file' => $item['test_file'],
                        'paths' => Capability::suiteLocation(),
                    ]),
                default => null,
            };

            if (is_string($finding)) {
                $findings[] = $finding;
            }
        }

        // A test for what was already true guards it, but at least one
        // new test must fail without the change, or nothing shows it works.
        if (NewTests::ending($measured, NewTests::PASSED, $patch) !== [] && NewTests::ending($measured, NewTests::FAILED, $patch) === []) {
            $findings[] = __('Every test the change added passes without it too, so nothing shows that the change works. Add a test that fails without the change and passes with it. Tests of what was already true can stay.');
        }

        return $findings;
    }
}
