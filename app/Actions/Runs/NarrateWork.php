<?php

namespace App\Actions\Runs;

use App\Context\ProjectNotes;
use App\Models\Run;
use App\Models\RunEvent;
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

    protected const JARGON = '/\b(prompt|instructions?|acceptance|repository|repo|git|commit|artisan|phpunit|pest|phpstan|larastan|pint|composer|npm|controller|middleware|namespace|migration|eloquent|blade|inertia|livewire|vue|typescript|schema|endpoint|seeder|lint|refactor|diff|stack trace|test suite)\b/i';

    /**
     * Tell the owner how the change was made, step by step, in their words:
     * what was thought about, which parts of the app were looked at or
     * changed, and when it was tried out. Everything comes from what the
     * coding agent did; its own words are kept only where they are plain.
     * While it works, the live story continues the saved ones.
     *
     * @param  list<array{kind: string, text?: string, file?: string}>|null  $live
     * @return list<array{kind: string, text: string}>
     */
    public function handle(Run $run, ?array $live = null): array
    {
        $stories = $run->events()
            ->where('type', 'agent_story')
            ->get()
            ->map(fn (RunEvent $event) => $event->data['story'] ?? [])
            ->all();

        if ($live !== null) {
            $stories[] = $live;
        }

        $lines = [];

        foreach ($stories as $index => $story) {
            if ($index > 0 && $lines !== []) {
                $lines[] = ['kind' => 'repair', 'names' => []];
            }

            foreach ($story as $entry) {
                $this->add($lines, $run, $entry);
            }
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
            'read' => ['kind' => 'read', 'names' => $this->names($run, $entry['file'] ?? '', steps: false)],
            'changed' => $this->changed($run, $entry['file'] ?? ''),
            'testing' => ['kind' => 'tried', 'names' => []],
            default => null,
        };

        if ($line === null) {
            return;
        }

        $last = array_key_last($lines);

        if ($last !== null && $lines[$last]['kind'] === $line['kind'] && $line['kind'] !== 'thought') {
            $lines[$last]['names'] = array_values(array_unique([...$lines[$last]['names'], ...$line['names']]));

            return;
        }

        if ($last !== null && $line['kind'] === 'thought' && ($lines[$last]['text'] ?? null) === ($line['text'] ?? null)) {
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
     * @param  array{kind: string, names: list<string>, text?: string}  $line
     * @return array{kind: string, text: string}
     */
    protected function render(array $line): array
    {
        if ($line['kind'] === 'thought') {
            return ['kind' => 'thought', 'text' => $line['text'] ?? ''];
        }

        $names = Arr::join(array_slice($line['names'], 0, 3), ', ', ' and ');

        $text = match ($line['kind']) {
            'read' => $names === '' ? __('Looked around your app') : __('Looked at how :areas works', ['areas' => $names]),
            'changed' => $names === '' ? __('Changed a part of your app') : __('Worked on :areas', ['areas' => $names]),
            'tested' => __('Wrote a test for it'),
            'tried' => __('Tried it out'),
            'repair' => __('Went back to fix what the checks found'),
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

            if (str_word_count($sentence) < 5 || mb_strlen($sentence) > 240 || preg_match(self::CODE, $sentence) === 1 || preg_match(self::JARGON, $sentence) === 1) {
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
