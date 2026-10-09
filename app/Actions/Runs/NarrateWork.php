<?php

namespace App\Actions\Runs;

use App\Context\ProjectNotes;
use App\Enums\RunStatus;
use App\Features\OwnerWording;
use App\Jobs\VerifyFeatureRequest;
use App\Models\Run;
use App\Models\Verification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class NarrateWork
{
    /**
     * Code, file names and identifiers (case matters here), and words that
     * mean a sentence is written for a developer, not the owner. Such a
     * sentence is left out rather than half translated.
     */
    protected const CODE = '/`|::|->|\$|\(\)|\{|\}|[\w-]+\/[\w.-]+|\b[\w-]+\.(php|js|ts|mjs|vue|json|md|env|ya?ml|css|blade|xml|lock|sh)\b|\b[a-z]+_[a-z_]+\b|\b[a-z]+[A-Z]\w*\b|\b[A-Z][a-z]+[A-Z]\w*\b/';

    /**
     * What each check did, in the owner's words, once it has run.
     */
    protected const CHECKS = [
        'Tests' => 'Ran your app\'s tests',
        'Static analysis' => 'Read the code for mistakes',
        'PHP formatting' => 'Checked the code is tidy',
        'Frontend format and lint' => 'Checked the screens\' code is tidy',
        'TypeScript' => 'Read the screens\' code for mistakes',
        'Production caches' => 'Checked your app can go online',
        'Strict models' => 'Checked what the change saves',
    ];

    /**
     * Words the agent uses when it guesses at how it is run and checked
     * ("the grading harness", "as the brief requires"). How we work is
     * ours, so these lines never reach the owner.
     */
    protected const OURS = '/\b(harness|grad(ing|ed|er)|the brief|sandbox(ed)?|evaluator|benchmark)\b/i';

    protected const JARGON = '/\b(prompt|instructions?|acceptance|repository|repo|git|commit|artisan|phpunit|pest|phpstan|larastan|pint|composer|npm|controller|middleware|namespace|migration|eloquent|blade|inertia|livewire|vue|typescript|schema|endpoint|seeder|lint|refactor|diffs?|codebase|css|stack trace|test suite|frontend|backend|vitest|jest|php|json|utc|tailwind|ssr|api|payload|seriali[sz]\w*|pivot|fixtures?|helpers?|describe block|deterministic|regex|sql|attributes?|components?|props|enum|auto-?fixer)\b/i';

    /**
     * Tell the owner how the change was made, step by step, in their words:
     * each stage of the work (planning, checking, looking it over, going
     * back to fix something), and within it what was thought about, which
     * parts of the app were looked at or changed, and when it was tried
     * out. The steps come from what the coding agent did; its own words
     * are kept only where they are plain. While it works, the live story
     * continues the saved one.
     *
     * @param  list<array{kind: string, text?: string, file?: string}>|null  $live
     * @return list<array{kind: string, text: string}>
     */
    public function handle(Run $run, ?array $live = null): array
    {
        $lines = [];
        // Each time the change is checked, a new verification records each
        // check as it finishes; the n-th check stage gets the n-th one.
        $verifications = $run->verifications()->oldest('id')->get();

        // Files the owner's own tool has been seen changing, so each counts once.
        $seen = [];

        foreach ($run->events()->whereIn('type', ['status', 'review', 'agent_story', 'worker_progress', 'worker_tried'])->get() as $event) {
            if ($event->type === 'agent_story') {
                foreach ($event->data['story'] ?? [] as $entry) {
                    $this->add($lines, $run, $entry);
                }

                continue;
            }

            // The owner's own tool says what it does in its own words, and
            // its tries show which parts of the app it changed by then.
            if ($event->type === 'worker_progress') {
                $this->add($lines, $run, ['kind' => 'said', 'text' => (string) ($event->data['text'] ?? '')]);

                continue;
            }

            if ($event->type === 'worker_tried') {
                foreach (array_diff(array_filter((array) ($event->data['files'] ?? []), is_string(...)), $seen) as $file) {
                    $this->add($lines, $run, ['kind' => 'changed', 'file' => $file]);
                    $seen[] = $file;
                }

                $this->add($lines, $run, ['kind' => 'testing']);

                continue;
            }

            $text = OwnerWording::event($event);
            $last = array_key_last($lines);

            if ($text !== null && ($last === null || ($lines[$last]['text'] ?? null) !== $text)) {
                $lines[] = ['kind' => 'stage', 'names' => [], 'text' => $text];
            }

            if ($event->type === 'review' && ! ($event->data['approved'] ?? false)) {
                array_push($lines, ...$this->caught($event->data['findings'] ?? []));
            }

            if ($event->type === 'status' && ($event->data['to'] ?? null) === RunStatus::Verifying->value && ($verification = $verifications->shift()) !== null) {
                array_push($lines, ...$this->checks($verification));
            }
        }

        foreach ($live ?? [] as $entry) {
            $this->add($lines, $run, $entry);
        }

        return array_map($this->render(...), $lines);
    }

    /**
     * Add one thing the agent did, folding it into the line before when it
     * is more of the same, so twenty files read make one line.
     *
     * @param  list<array{kind: string, names: list<string>, text?: string}>  $lines
     * @param  array{kind: string, text?: string, file?: string}  $entry
     */
    protected function add(array &$lines, Run $run, array $entry): void
    {
        $line = match ($entry['kind']) {
            'said' => ($text = $this->plain($entry['text'] ?? '')) === null ? null : ['kind' => 'thought', 'names' => [], 'text' => $text],
            // Its reasons before it acts, kept as plainly as what it says.
            'thinking' => ($text = $this->plain($entry['text'] ?? '')) === null ? null : ['kind' => 'thinking', 'names' => [], 'text' => $text],
            'read' => ['kind' => 'read', 'names' => $this->names($run, $entry['file'] ?? '', steps: false)],
            'changed' => $this->changed($run, $entry['file'] ?? ''),
            'testing' => ['kind' => 'tried', 'names' => []],
            default => null,
        };

        if ($line === null) {
            return;
        }

        $last = array_key_last($lines);

        $words = in_array($line['kind'], ['thought', 'thinking'], true);

        if ($last !== null && $lines[$last]['kind'] === $line['kind'] && ! $words) {
            $lines[$last]['names'] = array_values(array_unique([...$lines[$last]['names'], ...$line['names']]));

            return;
        }

        if ($last !== null && $words && ($lines[$last]['text'] ?? null) === ($line['text'] ?? null)) {
            return;
        }

        $lines[] = $line;
    }

    /**
     * @return array{kind: string, names: list<string>}
     */
    protected function changed(Run $run, string $file): array
    {
        return match (true) {
            str_starts_with($file, ProjectNotes::directory().'/') => ['kind' => 'noted', 'names' => []],
            str_starts_with($file, 'tests/') => ['kind' => 'tested', 'names' => []],
            default => ['kind' => 'changed', 'names' => $this->names($run, $file, steps: true)],
        };
    }

    /**
     * Name what a file is about for the owner: the plan step it carries out
     * (in quotes, as the owner saw it in the plan), or else the area of the
     * app it belongs to.
     *
     * @return list<string>
     */
    protected function names(Run $run, string $file, bool $steps): array
    {
        if ($steps) {
            foreach ($run->plan['steps'] ?? [] as $step) {
                if ($step['file'] === $file) {
                    return ['“'.$step['label'].'”'];
                }
            }
        }

        foreach ($run->context['outline'] ?? [] as $area) {
            if (Str::is($area['paths'], $file)) {
                return [$area['name']];
            }
        }

        return [];
    }

    /**
     * Say what each finished check found, so checking reads as work done
     * rather than a wait: the app's tests with how many pass, each check
     * on the code, and trying the change as the owner asked, as one line.
     *
     * @return list<array{kind: string, names: list<string>, text: string}>
     */
    protected function checks(Verification $verification): array
    {
        $lines = [];
        $tried = null;

        foreach ($verification->results ?? [] as $result) {
            if (! in_array($result['outcome'], [VerifyFeatureRequest::OUTCOME_PASSED, VerifyFeatureRequest::OUTCOME_FAILED], true)) {
                continue;
            }

            $passed = $result['outcome'] === VerifyFeatureRequest::OUTCOME_PASSED;

            if ($result['stage'] === 'acceptance') {
                $tried = ($tried ?? true) && $passed;

                continue;
            }

            if ($result['stage'] !== 'checks') {
                continue;
            }

            $did = isset(self::CHECKS[$result['name']]) ? __(self::CHECKS[$result['name']]) : __('Ran “:name”', ['name' => $result['name']]);
            $count = count(array_filter($result['tests'] ?? [], fn (array $test) => $test['outcome'] === 'passed'));

            // A check that failed the same way before the change is the
            // app's old problem, not something the change broke.
            $old = ! $passed && ($result['at_start'] ?? null) === VerifyFeatureRequest::OUTCOME_FAILED && ($result['new_problems'] ?? []) === [];

            $lines[] = match (true) {
                $passed && $count > 0 => ['kind' => 'passed', 'names' => [], 'text' => trans_choice(':did: the one test passes|:did: all :count pass', $count, ['did' => $did])],
                $passed => ['kind' => 'passed', 'names' => [], 'text' => $did],
                $old => ['kind' => 'known', 'names' => [], 'text' => __(':did: it found a problem that was there before this change', ['did' => $did])],
                default => ['kind' => 'failed', 'names' => [], 'text' => __(':did: it found a problem', ['did' => $did])],
            };
        }

        if ($tried !== null) {
            $lines[] = ['kind' => $tried ? 'passed' : 'failed', 'names' => [], 'text' => (string) ($tried ? __('Tried it the way you asked for it, and it works') : __('Tried it the way you asked for it, and it did not work yet'))];
        }

        return $lines;
    }

    /**
     * Say what the second look caught, where the owner can read it: the
     * first sentence of each problem that sent the change back. Most
     * are about missing tests, which the checks above already show; only
     * problems with what the app does are told.
     *
     * @param  list<array{severity?: string, summary?: string}>  $findings
     * @return list<array{kind: string, names: list<string>, text: string}>
     */
    protected function caught(array $findings): array
    {
        $lines = [];
        $tests = false;

        foreach ($findings as $finding) {
            // Only the first sentence says what is wrong; a later one read
            // alone ("It is missing") makes no sense.
            // Where it is ("Line 13 of resources/js/Welcome.vue") means
            // nothing to the owner, and would hide what is wrong.
            $summary = preg_replace(['/^Line \d+ of \S+\s+/i', '/\s*\([^)]*\/[^)]*\)/'], ['The change ', ''], trim((string) ($finding['summary'] ?? ''))) ?? '';
            $first = ($finding['severity'] ?? null) === 'blocking' ? $this->plain(preg_split('/(?<=[.!?])\s+/', $summary)[0] ?? '') : null;

            if ($first !== null && preg_match('/\b(tests?|tested|criteri(on|a)|verif\w*|plan|evidence)\b/i', $first) !== 1) {
                $lines[] = ['kind' => 'failed', 'names' => [], 'text' => $first];
            } elseif ($first !== null) {
                $tests = true;
            }
        }

        // A finding about tests reads as jargon, but "I found something to
        // fix" alone leaves the owner guessing what it was.
        if ($lines === [] && $tests) {
            $lines[] = ['kind' => 'failed', 'names' => [], 'text' => __('It needed a test that proves what you asked for.')];
        }

        return array_slice($lines, 0, 3);
    }

    /**
     * @param  array{kind: string, names: list<string>, text?: string}  $line
     * @return array{kind: string, text: string}
     */
    protected function render(array $line): array
    {
        if (in_array($line['kind'], ['thought', 'thinking', 'stage', 'passed', 'failed', 'known'], true)) {
            return ['kind' => $line['kind'], 'text' => $line['text'] ?? ''];
        }

        $names = Arr::join(array_slice($line['names'], 0, 3), ', ', ' and ');

        $text = match ($line['kind']) {
            'read' => $names === '' ? __('Looked around your app') : __('Looked at how :areas works', ['areas' => $names]),
            'changed' => $names === '' ? __('Changed a part of your app') : __('Worked on :areas', ['areas' => $names]),
            'tested' => __('Wrote a test for it'),
            'tried' => __('Tried it out'),
            default => __('Wrote down what I learned'),
        };

        return ['kind' => $line['kind'], 'text' => (string) $text];
    }

    /**
     * Keep the sentences of the agent's own words that the owner can read:
     * no code, file names or developer terms, and no filler such as "Let
     * me look." At most two sentences are kept.
     */
    public function plain(string $said): ?string
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\R+/', $said) ?: [];
        $kept = [];

        foreach ($sentences as $sentence) {
            $sentence = Str::squish(preg_replace('/^\s*(?:[-*•>#]+|\d+[.)])\s*/', '', $sentence) ?? '');
            $sentence = preg_replace('/^(now|ok(ay)?|great|perfect|good|excellent|alright|all right|done)\b[,!.]?\s*/i', '', $sentence) ?? '';
            $sentence = preg_replace('/\*\*|__/', '', $sentence) ?? '';

            if (str_word_count($sentence) < 5 || mb_strlen($sentence) > 240 || preg_match(self::CODE, $sentence) === 1 || preg_match(self::JARGON, $sentence) === 1 || preg_match(self::OURS, $sentence) === 1 || str_ends_with($sentence, ':')) {
                continue;
            }

            $kept[] = Str::ucfirst($sentence);

            if (count($kept) === 2) {
                break;
            }
        }

        return $kept === [] ? null : implode(' ', $kept);
    }
}
