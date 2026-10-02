<?php

namespace App\Actions\Features;

use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\UpdateProjectNotes;
use App\Actions\Runs\CompleteRunVerification;
use App\Context\NotesDocument;
use App\Enums\VerificationStatus;
use App\Features\AppBoundaries;
use App\Features\AppFaults;
use App\Features\AppRoutes;
use App\Features\AppTraces;
use App\Features\CodeShortcuts;
use App\Features\InventedColours;
use App\Features\NewCode;
use App\Features\NewTests;
use App\Features\PatchSummary;
use App\Features\ScreenCheck;
use App\Features\UndescribedImages;
use App\Features\UnsafeCode;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Verification;
use App\Projects\ProjectRepository;

class DescribeProof
{
    public function __construct(
        private ProjectRepository $repository,
        private ReadProjectContext $readProjectContext,
    ) {}

    /**
     * Say, in the owner's words, how we know a change works: what the checks
     * proved, how far the app's own tests reached into the change, and what
     * nothing checks yet. A preview only shows that a change looks right;
     * this shows why it can be trusted, which is what sets us apart.
     *
     * Only facts the checks recorded, never a promise: nothing is said
     * until the checks pass, and gaps are said as plainly as passes. A line
     * marked as evidence shows the change's own behaviour was tried (not
     * only that the rest still works); the owner's verdict rests on it.
     *
     * @return list<array{kind: string, text: string, pictures?: list<array{url: string, label: string}>, evidence?: bool, topic?: string}>
     */
    public function handle(FeatureRequest $featureRequest): array
    {
        $verification = $featureRequest->verifications()->latest('id')->first();

        // A check that failed just as it did before the change is the app's
        // old problem: the change broke nothing, so what it proved still
        // stands and is said.
        if (! in_array($verification?->status, [VerificationStatus::Passed, VerificationStatus::Unverified], true)
            && ($verification?->status !== VerificationStatus::Failed || app(CompleteRunVerification::class)->failures($verification) !== [])) {
            return [];
        }

        $lines = [...$this->checks($verification), ...$this->caught($featureRequest), ...$this->added($featureRequest, $verification), ...$this->about(__('safety'), $this->safety($featureRequest)), ...$this->about(__('sign-in'), $this->access($verification)), ...$this->about(__('speed'), $this->shortcuts($featureRequest, $verification)), ...$this->about(__('your colours'), $this->colours($featureRequest)), ...$this->about(__('pictures'), $this->pictures($featureRequest)), ...$this->about(__('phones and tablets'), $this->screens($featureRequest, $verification)), ...$this->code($verification), ...$this->about(__('what it saves'), $this->watched($verification)), ...$this->about(__('when it saves'), $this->steady($verification)), ...$this->about(__('what goes wrong'), $this->failed($verification)), ...$this->reach($featureRequest->latestRun, $verification), ...$this->approach($featureRequest->latestRun), ...$this->guidance($featureRequest), ...$this->rules($featureRequest)];

        // Two measurements can find the same gap; it is said once.
        return array_values(collect($lines)->unique('text')->all());
    }

    /**
     * Name what the passed lines checked, in a word or two, so the folded
     * passes can say what they cover ("safety, sign-in and speed").
     *
     * @param  list<array{kind: string, text: string, pictures?: list<array{url: string, label: string}>, evidence?: bool}>  $lines
     * @return list<array{kind: string, text: string, pictures?: list<array{url: string, label: string}>, evidence?: bool, topic?: string}>
     */
    protected function about(string $topic, array $lines): array
    {
        return array_map(fn (array $line) => $line['kind'] === 'passed' ? [...$line, 'topic' => $topic] : $line, $lines);
    }

    /**
     * Describe the checks that passed: the app's own tests, the other checks
     * on the code, and the separate checks written apart from the change.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
     */
    protected function checks(Verification $verification): array
    {
        $tests = 0;
        $old = 0;
        $others = 0;
        $separate = false;
        $audited = false;
        $warned = false;

        foreach ($verification->results ?? [] as $result) {
            // Only a finished lookup says anything; one that could not run
            // (no network, no lock file) is silent.
            if ($result['stage'] === 'security') {
                $audited = $audited || in_array($result['outcome'], ['passed', 'failed'], true);
                $warned = $warned || $result['outcome'] === 'failed';

                continue;
            }

            // Failing before the change too: its other tests still pass.
            if ($result['outcome'] === 'failed' && $result['stage'] === 'checks' && isset($result['tests'])) {
                $tests += count(array_filter($result['tests'], fn (array $test) => $test['outcome'] === 'passed'));
                $old += count(array_filter($result['tests'], fn (array $test) => $test['outcome'] === 'failed'));

                continue;
            }

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
            $tests > 0 && $old === 0 ? ['kind' => 'passed', 'text' => trans_choice('The app\'s own test still passes.|All :count of the app\'s own tests still pass.', $tests)] : null,
            $tests > 0 && $old > 0 ? ['kind' => 'passed', 'text' => trans_choice(':count of the app\'s own tests still passes.|:count of the app\'s own tests still pass.', $tests)] : null,
            $old > 0 ? ['kind' => 'gap', 'text' => trans_choice('One test was already failing before this change. Ask me to fix it.|:count tests were already failing before this change. Ask me to fix them.', $old)] : null,
            $others > 0 ? ['kind' => 'passed', 'text' => trans_choice(':count more check on the code passed.|:count more checks on the code passed.', $others)] : null,
            $separate ? ['kind' => 'passed', 'text' => __('Separate checks, written before the work began, pass too.'), 'evidence' => true] : null,
            // No separate checks were written for this change, so only its own
            // tests, if any, try it. That is a gap, said here as the verdict
            // above the lines would otherwise call the change well checked.
            $verification->status === VerificationStatus::Unverified ? ['kind' => 'gap', 'text' => __('Only the tests it wrote for itself tried what it does.')] : null,
            $audited && ! $warned ? ['kind' => 'passed', 'text' => __('No known security problems in the packages your app uses.')] : null,
            $warned ? ['kind' => 'gap', 'text' => __('Some packages your app uses have known security problems. Ask me to update them.')] : null,
        ]));
    }

    /**
     * Describe the problems caught and fixed before the owner saw the change:
     * each time failing checks or the second look sent the work back.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
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
     * When the new tests were also run without the change, only those that
     * failed there are evidence: they tried what the change does. When
     * none failed there, that is said as a gap: a test that passes with
     * and without a change does not show the change works.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
     */
    protected function added(FeatureRequest $featureRequest, Verification $verification): array
    {
        $measured = $verification->evidence['new_tests'] ?? [];
        $proving = NewTests::ending($measured, NewTests::FAILED, $featureRequest->patch);
        $passing = NewTests::ending($measured, NewTests::PASSED, $featureRequest->patch);

        if ($proving !== []) {
            return [['kind' => 'passed', 'text' => trans_choice('It added a test that fails without this change and passes with it: ":test".|It added :count tests that fail without this change and pass with it, such as ":test".', count($proving), ['test' => $proving[0]]), 'evidence' => true]];
        }

        if ($passing !== []) {
            return [['kind' => 'gap', 'text' => trans_choice('The test it added passes without this change too, so it does not show that the change works.|The :count tests it added pass without this change too, so they do not show that the change works.', count($passing))]];
        }

        $tests = PatchSummary::addedTests($featureRequest->patch);

        if ($tests === []) {
            return [];
        }

        return [['kind' => 'passed', 'text' => trans_choice('It added a test that keeps this checked from now on: ":test".|It added :count tests that keep this checked from now on, such as ":test".', count($tests), ['test' => $tests[0]]), 'evidence' => true]];
    }

    /**
     * Say that the change's code was scanned for common safety mistakes and
     * none were left, when the scan is on and the change has code it reads.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
     */
    protected function safety(FeatureRequest $featureRequest): array
    {
        if (! config('builder.verification.safety_scan') || ! UnsafeCode::scans($featureRequest->patch) || UnsafeCode::found($featureRequest->patch) !== []) {
            return [];
        }

        return [['kind' => 'passed', 'text' => __('Its code was checked for common safety mistakes, such as unsafe text on a page or unsafe database lookups. None were found.')]];
    }

    /**
     * Say what the change did to who may use the app's addresses, when it
     * changed which addresses the app answers. The list comes from the
     * running framework, before and after the change.
     *
     * An address that lost a check on who may use it is a gap: nothing
     * that ran can tell whether the owner wanted that. When every changed
     * address kept its checks, that is said, but only for the checks
     * Laravel names itself; an address that lost other middleware is left
     * unsaid.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function access(Verification $verification): array
    {
        $routes = $verification->evidence['routes'] ?? [];

        if ($routes === []) {
            return [];
        }

        $opened = AppRoutes::opened($routes);

        if ($opened !== []) {
            return [['kind' => 'gap', 'text' => trans_choice('A part of your app no longer checks who may use it: :address. Make sure you wanted that.|:count parts of your app no longer check who may use them, such as :address. Make sure you wanted that.', count($opened), ['address' => AppRoutes::address($opened[0]['route'])])]];
        }

        if (array_any($routes['changed'] ?? [], fn (array $route) => $route['lost'] !== [])) {
            return [];
        }

        return [['kind' => 'passed', 'text' => __('Every part of your app that asks people to sign in still does.')]];
    }

    /**
     * Say how many of the change's new lines of code a test ran, from the
     * line-by-line coverage of the suite. When a large share was run by no
     * test, that is a gap.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
     */
    protected function code(Verification $verification): array
    {
        $code = $verification->evidence['new_code'] ?? null;

        if ($code === null) {
            return [];
        }

        return array_values(array_filter([
            $code['run'] > 0 ? ['kind' => 'reach', 'text' => $code['run'] === $code['lines']
                ? trans_choice('Tests ran its one new line of code.|Tests ran every one of its :count new lines of code.', $code['lines'])
                : __('Tests ran :run of its :lines new lines of code.', ['run' => $code['run'], 'lines' => $code['lines']]), 'evidence' => true] : null,
            NewCode::gap($code) ? ['kind' => 'gap', 'text' => __('Some of the new code is not run by any test yet.')] : null,
        ]));
    }

    /**
     * Say what the app was seen to do while its tests used the change's
     * new code: each request was recorded, with what it saved and sent.
     * Three things the record shows by itself are gaps the owner reads,
     * each with the address where it happened, because only the owner
     * knows whether it was wanted. A clean record is said only when a
     * recorded request ran the new code.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function watched(Verification $verification): array
    {
        $traces = $verification->evidence['traces'] ?? null;

        if ($traces === null || $traces['reached'] === 0) {
            return [];
        }

        $gaps = [
            AppTraces::SAVED_ON_READ => 'Opening :address changes what your app has saved. A page that only shows things should leave them as they are. Make sure you wanted that.',
            AppTraces::KEPT_AFTER_REFUSAL => 'When your app says no at :address, it still keeps part of what was sent. Make sure you wanted that.',
            AppTraces::SENT_BEFORE_SAVED => 'At :address your app sends something before it has finished saving. If saving fails, it is sent anyway.',
        ];
        $lines = [];

        foreach ($gaps as $kind => $text) {
            $found = AppTraces::findings($traces, $kind);

            if ($found !== []) {
                $lines[] = ['kind' => 'gap', 'text' => __($text, ['address' => AppRoutes::address($found[0]['route'])])];
            }
        }

        if ($lines !== []) {
            return $lines;
        }

        // A test that only pretends to send hides when the app would send.
        return [['kind' => 'passed', 'text' => $traces['unseen'] > 0
            ? trans_choice(
                'We watched what your app saved while its tests used the new code. Nothing was saved by mistake. Its tests only pretend to send emails and messages, so we could not watch when it sends them.|We watched what your app saved while its tests used the new code :count times. Nothing was saved by mistake. Its tests only pretend to send emails and messages, so we could not watch when it sends them.',
                $traces['reached'],
            )
            : trans_choice(
                'We watched what your app saved and sent while its tests used the new code. Nothing was saved by mistake or sent too early.|We watched what your app saved and sent while its tests used the new code :count times. Nothing was saved by mistake or sent too early.',
                $traces['reached'],
            )]];
    }

    /**
     * Say whether the change's new code saved or sent anything while the
     * app was checking who may do something, checking what was filled in,
     * or putting a page together (direction 33). Those parts can run many
     * times, or before the app says no, so what they save or send repeats
     * or stays. Each kind found is a gap with the address where it
     * happened. A clean line is said only when the recorder named those
     * parts and a recorded request ran the new code.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function steady(Verification $verification): array
    {
        $boundaries = $verification->evidence['boundaries'] ?? null;

        if ($boundaries === null || ($verification->evidence['traces']['reached'] ?? 0) === 0) {
            return [];
        }

        $gaps = [
            AppBoundaries::CHANGED_WHILE_AUTHORIZING => 'At :address your app saves or sends something while it checks who may do something. That check can run many times, for example once for each item on a page, so it happens again each time.',
            AppBoundaries::CHANGED_WHILE_VALIDATING => 'At :address your app saves or sends something while it checks what was filled in. If it then says no, what it saved or sent stays.',
            AppBoundaries::CHANGED_WHILE_RENDERING => 'At :address your app saves or sends something while it puts the page together. That can happen more than once each time the page opens.',
        ];
        $lines = [];

        foreach ($gaps as $kind => $text) {
            $found = AppBoundaries::findings($boundaries, $kind);

            if ($found !== []) {
                $lines[] = ['kind' => 'gap', 'text' => __($text, ['address' => AppRoutes::address($found[0]['route'])])];
            }
        }

        if ($lines !== [] || $boundaries['phased'] === $boundaries['unknown']) {
            return $lines;
        }

        return [['kind' => 'passed', 'text' => __('While its tests used the new code, your app never saved or sent anything while checking who may do something, checking what was filled in, or putting a page together.')]];
    }

    /**
     * Say what the app left behind when one thing was made to fail while
     * its tests used the change's new code: an email that could not be
     * sent, an outside service that did not answer, a save that did not
     * work, or work the app does later that ran a second time. Each kind
     * of thing left behind, done twice, or asked for twice, is a gap the owner reads,
     * with the address where it happened. That nothing was left behind is
     * said only when a failure was really caused.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function failed(Verification $verification): array
    {
        $faults = $verification->evidence['faults'] ?? null;

        if ($faults === null || $faults['run'] === 0) {
            return [];
        }

        $gaps = [
            AppFaults::SAVED_THEN_FAILED => 'If :failure at :address, the person sees an error, but your app has already saved what they did. They may try again and do it twice.',
            AppFaults::SENT_THEN_LOST => 'If saving fails at :address, your app has already sent something. People are told about something that was not saved.',
            AppFaults::SAVED_IN_PART => 'If saving fails at :address, your app keeps one part of what it was saving and loses the rest.',
            AppFaults::DONE_TWICE => 'Your app does some work on its own after someone uses :address. If that work is cut off and starts over, it sends or adds the same thing twice.',
            AppFaults::SENT_AGAIN => 'Your app does some work on its own after someone uses :address. If saving fails during that work and it starts over, it sends the same thing twice.',
            AppFaults::CALLED_AGAIN => 'If an outside service is slow to answer at :address, your app asks it again. The service may then do the same thing twice, such as take a payment twice.',
            AppFaults::ANSWER_NOT_CHECKED => 'If an outside service says it could not do what your app asked at :address, your app does not look at that answer. It carries on as if the service did it.',
            AppFaults::NEEDS_JOB_DONE => 'Your app does some work on its own after someone uses :address, and does not wait for it. But what your app does next only goes right when that work is already done.',
            AppFaults::DEPENDS_ON_ORDER => 'When someone uses :address, your app does a few things one after the other, and nothing says which comes first. When they happen the other way round, your app does not do the same things.',
        ];
        // The recording already said that this is sent before saving ends.
        $said = AppTraces::findings($verification->evidence['traces'] ?? null, AppTraces::SENT_BEFORE_SAVED) !== [];
        // Work that sends twice each time it starts over is said once.
        $twice = AppFaults::findings($faults, AppFaults::DONE_TWICE) !== [];
        $lines = [];

        foreach ($gaps as $kind => $text) {
            $found = AppFaults::findings($faults, $kind);

            if ($found !== [] && ! ($said && $kind === AppFaults::SENT_THEN_LOST) && ! ($twice && $kind === AppFaults::SENT_AGAIN)) {
                $lines[] = ['kind' => 'gap', 'text' => __($text, [
                    'address' => AppRoutes::address($found[0]['route']),
                    'failure' => str_starts_with($found[0]['failed'], 'mail') ? __('an email cannot be sent') : __('an outside service does not answer'),
                ])];
            }
        }

        if ($lines !== [] || $faults['findings'] !== []) {
            return $lines;
        }

        return [['kind' => 'passed', 'text' => trans_choice(
            'We made one thing go wrong while your app used the new code, such as an email that cannot be sent or a save that fails. Your app left nothing half done.|We made things go wrong :count times while your app used the new code, such as an email that cannot be sent or a save that fails. Each time, your app left nothing half done.',
            $faults['run'],
        )]];
    }

    /**
     * Say that the change's code was read for shortcuts that slow an app
     * down or hide its errors and none were left, when the scan ran.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
     */
    protected function shortcuts(FeatureRequest $featureRequest, Verification $verification): array
    {
        if (! config('builder.verification.shortcuts.enabled') || $verification->shortcuts === null || CodeShortcuts::found($verification->shortcuts, $featureRequest->patch) !== []) {
            return [];
        }

        return [['kind' => 'passed', 'text' => __('Its code was checked for shortcuts that slow an app down or hide its errors, such as asking the database once for every row. None were found.')]];
    }

    /**
     * Say that the change's screens were checked for made-up colours and
     * none were left, when the scan is on and the change has screens.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
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
     * @return list<array{kind: string, text: string, evidence?: bool}>
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
     * broken, when the screen check measured them. Words too faint to read
     * and controls that hide keyboard focus are named as gaps.
     *
     * @return list<array{kind: string, text: string, pictures?: list<array{url: string, label: string}>, evidence?: bool}>
     */
    protected function screens(FeatureRequest $featureRequest, Verification $verification): array
    {
        $changed = ScreenCheck::changed($verification->screens, $featureRequest->patch);

        if (! config('builder.verification.screens.enabled') || $changed === [] || ScreenCheck::found($verification->screens, $featureRequest->patch) !== []) {
            return [];
        }

        $faint = ScreenCheck::faint($verification->screens, $featureRequest->patch);
        $lines = [[
            'kind' => 'passed',
            'text' => trans_choice('The screen it changed was opened on a phone, a tablet and a computer. Nothing was cut off, too small to tap or broken.|The :count screens it changed were opened on a phone, a tablet and a computer. Nothing was cut off, too small to tap or broken.', count($changed)),
            'pictures' => $this->screenPictures($verification),
            'evidence' => true,
        ]];

        if ($faint !== []) {
            $lines[] = ['kind' => 'gap', 'text' => trans_choice('Some words on it are hard to read against their background: ":text".|Some words on it are hard to read against their background, such as ":text".', count($faint), ['text' => $faint[0]['text']])];
        }

        $unfocused = ScreenCheck::unfocused($verification->screens, $featureRequest->patch);

        if ($unfocused !== []) {
            $lines[] = ['kind' => 'gap', 'text' => trans_choice('Someone using a keyboard cannot see when ":text" is selected.|Someone using a keyboard cannot see when some controls on it are selected, such as ":text".', count($unfocused), ['text' => $unfocused[0]['text']])];
        }

        return $lines;
    }

    /**
     * Get the pictures of the first changed screen the check took, narrowest
     * first, named for the device each width stands for.
     *
     * @return list<array{url: string, label: string}>
     */
    protected function screenPictures(Verification $verification): array
    {
        $shots = collect($verification->screens['shots'] ?? [])->map(fn (array $shot, int $index) => [...$shot, 'index' => $index]);
        $first = $shots->first();

        if ($first === null) {
            return [];
        }

        $labels = [(string) __('Phone'), (string) __('Tablet'), (string) __('Computer')];

        return array_values($shots->where('screen', $first['screen'])->sortBy('width')->values()
            ->map(fn (array $shot, int $position) => [
                'url' => route('verifications.shots.show', [$verification, $shot['index']]),
                'label' => $labels[min($position, 2)],
            ])->all());
    }

    /**
     * Say whether the change kept the app's old data and links working or
     * changed things cleanly, and why, as recorded when it was planned.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function approach(?Run $run): array
    {
        $choice = $run?->events()->where('type', 'compatibility')->latest('id')->first()?->data;

        if (! is_array($choice)) {
            return [];
        }

        $text = match ([(bool) $choice['keep_old_working'], (bool) $choice['chosen_by_owner']]) {
            [false, false] => __('Nobody uses your app yet, so I changed it cleanly and kept nothing for the old way.'),
            [false, true] => __('You chose not to keep the old way working, so I changed it cleanly.'),
            [true, false] => __('Your app may be in use, so I built this to keep the information and links it already has working.'),
            [true, true] => __('As you chose, I built this to keep the information and links your app already has working.'),
        };

        return [['kind' => 'approach', 'text' => $text]];
    }

    /**
     * Describe how far the app's own tests reached into the change, from
     * the map of which tests run which code, and whether it was looked over.
     *
     * @return list<array{kind: string, text: string, evidence?: bool}>
     */
    protected function reach(?Run $run, Verification $verification): array
    {
        $review = $run?->review;

        if ($review === null) {
            return [];
        }

        $observed = $review['classification']['observed'] ?? null;
        $names = array_column($run->context['outline'] ?? [], 'name', 'key');
        $lines = [];

        if ($observed !== null && ($observed['foundation'] ?? []) !== []) {
            $lines[] = ['kind' => 'reach', 'text' => __('It changed code the whole app shares, so every part of the app was tested.'), 'evidence' => true];
        } elseif ($observed !== null && $observed['tests'] > 0) {
            $areas = array_map(fn (string $key) => $names[$key] ?? $key, array_keys($observed['areas']));

            $lines[] = ['kind' => 'reach', 'text' => $areas === []
                ? trans_choice(':count of those tests runs the code this change touched.|:count of those tests run the code this change touched.', $observed['tests'])
                : trans_choice(':count of those tests runs the code this change touched, in :areas.|:count of those tests run the code this change touched, in :areas.', $observed['tests'], ['areas' => $this->join($areas)]), 'evidence' => true];
        }

        // The map is from the review; the checks measured the new code
        // since, line by line, and that later answer is the one said.
        if ($observed !== null && $observed['unmapped'] !== [] && ($verification->evidence['new_code'] ?? null) === null) {
            $lines[] = ['kind' => 'gap', 'text' => __('Some of the new code is not run by any test yet.')];
        }

        if ($review['approved']) {
            $lines[] = ['kind' => 'passed', 'text' => __('The change was looked over a second time before it reached you.')];
        }

        return $lines;
    }

    /**
     * Say that the second look held the change to the guidance the owner
     * kept from our developer, when there is some and it passed. The second
     * look is told to stop a change that goes against it, so this is what
     * it judged, not what a test proved: never evidence.
     *
     * @return list<array{kind: string, text: string}>
     */
    protected function guidance(FeatureRequest $featureRequest): array
    {
        if (($featureRequest->latestRun->review['approved'] ?? false) !== true) {
            return [];
        }

        $points = count(NotesDocument::parse($this->readProjectContext->current($featureRequest->project)->project ?? '')->items(UpdateProjectNotes::GUIDANCE_SECTION));

        return $points === 0 ? [] : [['kind' => 'passed', 'text' => trans_choice('A second look checked it against the guidance you kept from your developer.|A second look checked it against the :count points of guidance you kept from your developer.', $points)]];
    }

    /**
     * Name the things that must always be true in the parts of the app the
     * change touched, from the project notes (direction 18 §7). They say
     * what the change had to keep, not that a check proved it did, so they
     * are never evidence. One line per part, at most three rules in all,
     * so the proof stays short.
     *
     * @return list<array{kind: string, text: string, items: list<string>}>
     */
    protected function rules(FeatureRequest $featureRequest): array
    {
        $commit = $featureRequest->commit_sha;
        $project = $featureRequest->project;

        if ($commit === null || ! $this->repository->exists($project)) {
            return [];
        }

        $changed = $this->repository->git($project, ['diff-tree', '--no-commit-id', '--name-only', '-r', '--root', $commit], throw: false, timeout: 10);

        if (! $changed->successful()) {
            return [];
        }

        $paths = array_filter(explode("\n", trim($changed->output())));
        $left = 3;
        $lines = [];

        foreach ($this->readProjectContext->current($project)->capabilities as $capability) {
            $rules = array_slice($capability->rules(), 0, $left);

            if ($rules === [] || ! array_any($paths, fn (string $path) => $capability->claims($path))) {
                continue;
            }

            $lines[] = ['kind' => 'rule', 'text' => __('Must stay true in :area:', ['area' => $capability->name]), 'items' => $rules];

            if (($left -= count($rules)) === 0) {
                break;
            }
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
