<?php

namespace App\Actions\Features;

use App\Enums\VerificationStatus;
use App\Features\InventedColours;
use App\Features\PatchSummary;
use App\Features\ScreenCheck;
use App\Features\UndescribedImages;
use App\Features\UnsafeCode;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Verification;

class DescribeProof
{
    /**
     * Say, in the owner's words, how we know a change works: what the checks
     * proved, how far the app's own tests reached into the change, and what
     * nothing checks yet. A preview only shows that a change looks right;
     * this shows why it can be trusted, which is what sets us apart.
     *
     * Only facts the checks recorded, never a promise: nothing is said
     * until the checks pass, and gaps are said as plainly as passes.
     *
     * @return list<array{kind: string, text: string}>
     */
    public function handle(FeatureRequest $featureRequest): array
    {
        $verification = $featureRequest->verifications()->latest('id')->first();

        if (! in_array($verification?->status, [VerificationStatus::Passed, VerificationStatus::Unverified], true)) {
            return [];
        }

        return [...$this->checks($verification), ...$this->caught($featureRequest), ...$this->added($featureRequest), ...$this->safety($featureRequest), ...$this->colours($featureRequest), ...$this->pictures($featureRequest), ...$this->screens($featureRequest, $verification), ...$this->reach($featureRequest->latestRun)];
    }

    /**
     * Describe the checks that passed: the app's own tests, the other checks
     * on the code, and the separate checks written apart from the change.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function checks(Verification $verification): array
    {
        $tests = 0;
        $others = 0;
        $separate = false;

        foreach ($verification->results ?? [] as $result) {
            if ($result['outcome'] !== 'passed') {
                continue;
            }

            if ($result['stage'] === 'acceptance') {
                $separate = true;
            } elseif ($result['stage'] === 'checks' && isset($result['tests'])) {
                $tests += count(array_filter($result['tests'], fn (array $test) => $test['outcome'] === 'passed'));
            } elseif ($result['stage'] === 'checks') {
                $others++;
            }
        }

        return array_values(array_filter([
            $tests > 0 ? ['kind' => 'passed', 'text' => trans_choice('The app\'s own test still passes.|All :count of the app\'s own tests still pass.', $tests)] : null,
            $others > 0 ? ['kind' => 'passed', 'text' => trans_choice(':count more check on the code passed.|:count more checks on the code passed.', $others)] : null,
            $separate ? ['kind' => 'passed', 'text' => __('Separate checks, written before the work began, pass too.')] : null,
        ]));
    }

    /**
     * Describe the problems caught and fixed before the owner saw the change:
     * each time failing checks or the second look sent the work back.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function caught(FeatureRequest $featureRequest): array
    {
        $reasons = RunEvent::query()
            ->sentBack()
            ->whereIn('run_id', $featureRequest->runs()->select('id'))
            ->pluck('data')
            ->countBy(fn (array $data) => $data['reason']);

        return array_values(array_filter([
            $reasons->has('verification_failed') ? ['kind' => 'caught', 'text' => trans_choice('The checks caught a problem along the way, and it was fixed before you saw the change.|The checks caught :count problems along the way, and they were fixed before you saw the change.', $reasons['verification_failed'])] : null,
            $reasons->has('review_findings') ? ['kind' => 'caught', 'text' => trans_choice('A second look found something to fix, and it was fixed first.|A second look found something to fix :count times, and each was fixed first.', $reasons['review_findings'])] : null,
        ]));
    }

    /**
     * Name the tests the change added to the app, which keep what it does
     * checked on every later change. One is named, in its own words.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function added(FeatureRequest $featureRequest): array
    {
        $tests = PatchSummary::addedTests($featureRequest->patch);

        if ($tests === []) {
            return [];
        }

        return [['kind' => 'passed', 'text' => trans_choice('It added a test that keeps this checked from now on: ":test".|It added :count tests that keep this checked from now on, such as ":test".', count($tests), ['test' => $tests[0]])]];
    }

    /**
     * Say that the change's code was scanned for common safety mistakes and
     * none were left, when the scan is on and the change has code it reads.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function safety(FeatureRequest $featureRequest): array
    {
        if (! config('builder.verification.safety_scan') || ! UnsafeCode::scans($featureRequest->patch) || UnsafeCode::found($featureRequest->patch) !== []) {
            return [];
        }

        return [['kind' => 'passed', 'text' => __('Its code was checked for common safety mistakes, such as unsafe text on a page or unsafe database lookups. None were found.')]];
    }

    /**
     * Say that the change's screens were checked for made-up colours and
     * none were left, when the scan is on and the change has screens.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function colours(FeatureRequest $featureRequest): array
    {
        if (! config('builder.verification.design_scan') || ! InventedColours::scans($featureRequest->patch) || InventedColours::found($featureRequest->patch) !== []) {
            return [];
        }

        return [['kind' => 'passed', 'text' => __('Its screens take their colours from your app\'s theme. None were made up.')]];
    }

    /**
     * Say that the pictures the change added describe what they show, when
     * the scan is on and the change added any.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function pictures(FeatureRequest $featureRequest): array
    {
        if (! config('builder.verification.design_scan') || ! UndescribedImages::scans($featureRequest->patch) || UndescribedImages::found($featureRequest->patch) !== []) {
            return [];
        }

        return [['kind' => 'passed', 'text' => __('The pictures it added say what they show, for people who cannot see the screen.')]];
    }

    /**
     * Say that the screens the change touched were opened on a phone, a
     * tablet and a computer and nothing was cut off, too small to tap or
     * broken, when the screen check measured them, and name words too
     * faint to read as a gap.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function screens(FeatureRequest $featureRequest, Verification $verification): array
    {
        $changed = ScreenCheck::changed($verification->screens, $featureRequest->patch);

        if (! config('builder.verification.screens.enabled') || $changed === [] || ScreenCheck::found($verification->screens, $featureRequest->patch) !== []) {
            return [];
        }

        $faint = ScreenCheck::faint($verification->screens, $featureRequest->patch);
        $lines = [['kind' => 'passed', 'text' => trans_choice('The screen it changed was opened on a phone, a tablet and a computer. Nothing was cut off, too small to tap or broken.|The :count screens it changed were opened on a phone, a tablet and a computer. Nothing was cut off, too small to tap or broken.', count($changed))]];

        if ($faint !== []) {
            $lines[] = ['kind' => 'gap', 'text' => trans_choice('Some words on it are hard to read against their background: ":text".|Some words on it are hard to read against their background, such as ":text".', count($faint), ['text' => $faint[0]['text']])];
        }

        return $lines;
    }

    /**
     * Describe how far the app's own tests reached into the change, from
     * the map of which tests run which code, and whether it was looked over.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function reach(?Run $run): array
    {
        $review = $run?->review;

        if ($review === null) {
            return [];
        }

        $observed = $review['classification']['observed'] ?? null;
        $names = array_column($run->context['outline'] ?? [], 'name', 'key');
        $lines = [];

        if ($observed !== null && ($observed['foundation'] ?? []) !== []) {
            $lines[] = ['kind' => 'reach', 'text' => __('It changed code the whole app shares, so every part of the app was tested.')];
        } elseif ($observed !== null && $observed['tests'] > 0) {
            $areas = array_map(fn (string $key) => $names[$key] ?? $key, array_keys($observed['areas']));

            $lines[] = ['kind' => 'reach', 'text' => $areas === []
                ? trans_choice(':count of those tests runs the code this change touched.|:count of those tests run the code this change touched.', $observed['tests'])
                : trans_choice(':count of those tests runs the code this change touched, in :areas.|:count of those tests run the code this change touched, in :areas.', $observed['tests'], ['areas' => $this->join($areas)])];
        }

        if ($observed !== null && $observed['unmapped'] !== []) {
            $lines[] = ['kind' => 'gap', 'text' => __('Some of the new code is not run by any test yet.')];
        }

        if ($review['approved']) {
            $lines[] = ['kind' => 'passed', 'text' => __('The change was looked over a second time before it reached you.')];
        }

        return $lines;
    }

    /**
     * Join names as a sentence would: "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $names
     */
    protected function join(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' '.__('and').' '.$last;
    }
}
