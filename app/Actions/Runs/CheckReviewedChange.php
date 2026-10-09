<?php

namespace App\Actions\Runs;

use App\Actions\Context\AssessVerifyItems;
use App\Actions\Context\ReadProjectContext;
use App\Actions\Features\AcceptFindings;
use App\Actions\Features\ProposeFindings;
use App\Context\Capability;
use App\Features\AppBoundaries;
use App\Features\AppContainment;
use App\Features\AppDrift;
use App\Features\AppFaults;
use App\Features\AppRoutes;
use App\Features\BoundaryCode;
use App\Features\InventedColours;
use App\Features\MigrationChecks;
use App\Features\NarrowedFormats;
use App\Features\NewTests;
use App\Features\NodeInPhpTests;
use App\Features\OwnedRecords;
use App\Features\OwnFormatChecks;
use App\Features\PackagePolicy;
use App\Features\QueuedWork;
use App\Features\ScreenCheck;
use App\Features\UndescribedImages;
use App\Features\UnsafeCode;
use App\Models\FeatureRequest;
use App\Models\Verification;
use App\Runs\Plan;
use App\Runs\Review;

/**
 * Add the platform's own checks of a reviewed change to its review: each
 * verify item needs a test that ran, a new test must fail without the
 * change, the code scans and the measured pages must find nothing, and
 * the gate holds what running the app proved. No model decides them, so
 * a finding blocks the change whatever the reviewer said. The run's review
 * stage and the evaluation both use it, so the evaluation blocks what ships.
 */
class CheckReviewedChange
{
    public function __construct(
        protected AssessVerifyItems $assessVerifyItems,
        protected AcceptFindings $acceptFindings,
        protected ProposeFindings $proposeFindings,
        protected ReadProjectContext $readProjectContext,
    ) {}

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
     * Block a change on what the gate holds against it (direction 33). The
     * agent may ask to keep a finding, but only the owner's yes lets it
     * stay: until they answer, it is "asked" and holds the change without
     * being sent back. The rest are keyed for the agent to answer with.
     *
     * @return array{review: Review, gate: list<array{key: string|null, kind: string, identity: string, text: string}>, asked: list<array{kind: string, identity: string, text: string}>}
     */
    public function gate(Review $review, FeatureRequest $featureRequest, Verification $verification, bool $canRepair): array
    {
        $held = $canRepair ? $this->held($featureRequest, $verification) : [];
        $pending = $this->proposeFindings->pending($featureRequest);
        $asked = array_values(array_filter($held, fn (array $finding) => in_array($finding['identity'], $pending, true)));
        $gate = $this->proposeFindings->keyed($featureRequest, array_values(array_filter($held, fn (array $finding) => ! in_array($finding['identity'], $pending, true))));

        return ['review' => $review->withBlockingFindings(array_column($gate, 'text')), 'gate' => $gate, 'asked' => $asked];
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

    /**
     * Get what the gate holds against the change, each by what it is: what
     * a test run proves its code did where Laravel expects nothing to
     * change, and what a caused failure proves it left behind. In a part
     * the owner asked to be extra careful with, what was only read from
     * the code, and a call to an outside service from a new place, count
     * too (strict mode). What the owner said they want is left out.
     *
     * @return list<array{kind: string, identity: string, text: string}>
     */
    protected function held(FeatureRequest $featureRequest, Verification $verification): array
    {
        $accepted = $this->acceptFindings->identities($featureRequest);
        $evidence = $verification->evidence ?? [];
        $boundaries = AppBoundaries::without($evidence['boundaries'] ?? null, $accepted);
        $gate = [];

        if (config('builder.verification.boundaries.send_back')) {
            foreach ($boundaries['findings'] ?? [] as $finding) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => BoundaryCode::identity($finding), 'text' => AppBoundaries::finding($finding)];
            }
        }

        // A new address that changes data with no check on who may use
        // it, or one that lost its check (§12). The owner may want it, such
        // as a contact form, and says so in the proof.
        if (config('builder.verification.routes_send_back')) {
            foreach (AppRoutes::findings($evidence['routes'] ?? null, $accepted) as $finding) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => AppRoutes::identity($finding), 'text' => AppRoutes::finding($finding)];
            }
        }

        // A migration that did not run up, down and up again, or one that
        // already existed and was edited, breaks the owner's live data when
        // published (§9). The owner may keep one, such as a data migration
        // that cannot be undone on purpose.
        $migrations = $evidence['migrations'] ?? null;

        foreach (MigrationChecks::findings($migrations, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => MigrationChecks::identity($finding), 'text' => MigrationChecks::finding($finding, $migrations)];
        }

        // New queued work that does not say how it tries again or fails
        // fails quietly on the live app (§12). The owner may keep work that
        // must run once only.
        $queued = $evidence['queued'] ?? [];

        foreach (QueuedWork::findings($queued, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => QueuedWork::identity($finding), 'text' => QueuedWork::finding($finding, $queued)];
        }

        // Records that name an owner with nothing that keeps one owner's
        // from another (§12). The owner may keep records that are public
        // on purpose.
        $owners = $evidence['owners'] ?? [];

        foreach (OwnedRecords::findings($owners, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => OwnedRecords::identity($finding), 'text' => OwnedRecords::finding($finding, $owners)];
        }

        // A format made stricter that people's saved values fail (§9). The
        // old rule is kept until the owner says to turn them away.
        foreach (NarrowedFormats::findings($evidence['narrowed'] ?? null, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => NarrowedFormats::identity($finding), 'text' => NarrowedFormats::finding($finding)];
        }

        // New packages outside the dependency policy (§12, §13). The owner
        // may keep a package they chose.
        $packages = $evidence['packages'] ?? ['changes' => [], 'problems' => []];

        foreach (PackagePolicy::findings($packages, $accepted) as $finding) {
            $gate[] = ['kind' => $finding['kind'], 'identity' => PackagePolicy::identity($finding), 'text' => PackagePolicy::finding($finding, $packages)];
        }

        if (config('builder.verification.faults.send_back')) {
            foreach (AppFaults::without($evidence['faults'] ?? null, $accepted)['findings'] ?? [] as $finding) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => AppFaults::identity($finding), 'text' => AppFaults::finding($finding)];
            }
        }

        $careful = $featureRequest->project->careful_areas ?? [];

        // Work that grew far past an area's ceiling, in a careful area.
        foreach ($evidence['drift']['findings'] ?? [] as $finding) {
            $identity = AppDrift::identity($finding);

            if ($finding['far'] && in_array($finding['area'], $careful, true) && ! in_array($identity, $accepted, true)) {
                $gate[] = ['kind' => AppDrift::GREW, 'identity' => $identity, 'text' => AppDrift::finding($finding, $finding['name'])];
            }
        }

        if ($careful === [] || (($boundaries['read'] ?? []) === [] && ($evidence['containment']['findings'] ?? []) === [])) {
            return $gate;
        }

        $context = $this->readProjectContext->current($featureRequest->project);
        $names = array_map(fn (Capability $capability) => $capability->name, array_filter($context->capabilities, fn (Capability $capability) => in_array($capability->key, $careful, true)));

        foreach ($boundaries['read'] ?? [] as $finding) {
            if (array_intersect($context->claiming((string) preg_replace('/:\d+$/', '', $finding['at'])), $careful) !== []) {
                $gate[] = ['kind' => $finding['kind'], 'identity' => BoundaryCode::identity($finding), 'text' => AppBoundaries::readFinding($finding)];
            }
        }

        foreach ($evidence['containment']['findings'] ?? [] as $finding) {
            $identity = AppContainment::identity($finding);

            if (! in_array($identity, $accepted, true) && array_intersect([...$finding['from'], ...$finding['home']], $names) !== []) {
                $gate[] = ['kind' => AppContainment::CALLED_ELSEWHERE, 'identity' => $identity, 'text' => AppContainment::finding($finding)];
            }
        }

        return $gate;
    }
}
