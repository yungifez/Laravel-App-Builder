<?php

namespace App\Features;

/**
 * Shortcuts a coder takes in the app's PHP code that cost the owner later:
 * errors caught and ignored, and the database asked once per row. They
 * are found by the Sloppy analyser (heyosseus/sloppy), which reads the
 * code without running it or asking a model, so the same code always
 * gets the same answer.
 *
 * Only the lines a change adds count, so a shortcut the app already had
 * is never held against a change. A line is let through when it, the line
 * above or the line below carries a comment: a reason a person can read.
 */
class CodeShortcuts
{
    /**
     * The files read: the app's PHP code, not its tests.
     */
    protected const FILES = '/^(?!tests\/).*\.php$/';

    /**
     * What each shortcut costs the owner, and the way to avoid it, by the
     * analyser's rule. Rules not named here are not held against a change.
     *
     * @var array<string, array{problem: string, fix: string}>
     */
    protected const RULES = [
        'SL107' => [
            'problem' => 'catches an error and carries on without a trace, so when it goes wrong nobody can tell',
            'fix' => 'Let it fail, or record it with report() before going on.',
        ],
        'SL203' => [
            'problem' => 'reads a related record once for each item in a list, which slows the page as the list grows',
            'fix' => 'Load the relation with the list, with with() or load().',
        ],
        'SL204' => [
            'problem' => 'asks the database once for each item in a loop, which slows the page as the list grows',
            'fix' => 'Ask once for all of them before the loop.',
        ],
        'SL210' => [
            'problem' => 'loads every row of a table at once, which slows the page and fills memory as the table grows',
            'fix' => 'Ask the database for only the rows needed, with where(), a count, or paginate().',
        ],
    ];

    /**
     * A line that is a comment, and a comment at the end of a line.
     */
    protected const COMMENT_LINE = '/^\s*(\/\/|\/\*|\*|#)/';

    protected const COMMENT_END = '/(^|\s)(\/\/|\/\*)/';

    /**
     * The analyser's rule names, for its --rule option.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * Get the app's PHP files the patch adds or changes, the ones to read.
     *
     * @return list<string>
     */
    public static function files(?string $patch): array
    {
        return array_values(array_map(
            fn (array $file) => $file['path'],
            array_filter(PatchSummary::files($patch), fn (array $file) => preg_match(self::FILES, $file['path']) === 1 && ! str_contains($file['diff'], "\ndeleted file mode ")),
        ));
    }

    /**
     * Determine if the patch changes code the analyser reads, so a clean
     * scan says something.
     */
    public static function scans(?string $patch): bool
    {
        return self::files($patch) !== [];
    }

    /**
     * Read the analyser's JSON report into its findings, or null when it
     * is not a report.
     *
     * @return list<array{rule: string, path: string, line: int}>|null
     */
    public static function parse(string $report): ?array
    {
        $data = json_decode(trim($report), true);

        if (! is_array($data) || ($data['tool'] ?? null) !== 'sloppy' || ! is_array($data['findings'] ?? null)) {
            return null;
        }

        return array_values(array_map(fn (array $finding) => [
            'rule' => (string) $finding['rule'],
            'path' => (string) preg_replace('#^\./#', '', (string) $finding['file']),
            'line' => (int) $finding['line'],
        ], array_filter($data['findings'], fn ($finding) => is_array($finding) && is_string($finding['rule'] ?? null) && is_string($finding['file'] ?? null) && is_int($finding['line'] ?? null))));
    }

    /**
     * Find the shortcuts on the lines the patch adds, each once.
     *
     * @param  list<array{rule: string, path: string, line: int}>|null  $shortcuts
     * @return list<array{rule: string, path: string, line: int}>
     */
    public static function found(?array $shortcuts, ?string $patch): array
    {
        if ($shortcuts === null || $shortcuts === []) {
            return [];
        }

        $found = [];

        foreach (PatchSummary::files($patch) as $file) {
            $added = collect(PatchSummary::addedLines($file['diff']))->keyBy('line');

            foreach ($shortcuts as $shortcut) {
                $line = $added->get($shortcut['line']);

                if ($shortcut['path'] !== $file['path'] || $line === null || ! isset(self::RULES[$shortcut['rule']])) {
                    continue;
                }

                // A reason next to it lets it through, as a person reading it would.
                $below = $added->get($shortcut['line'] + 1)['text'] ?? '';

                if (preg_match(self::COMMENT_LINE, $line['previous']) === 1 || preg_match(self::COMMENT_END, $line['text']) === 1 || preg_match(self::COMMENT_LINE, $below) === 1) {
                    continue;
                }

                $found[$shortcut['path'].':'.$shortcut['line'].':'.$shortcut['rule']] = $shortcut;
            }
        }

        return array_values($found);
    }

    /**
     * Get the code the patch added on the shortcut's line, or null when the
     * patch did not add that line.
     *
     * @param  array{rule: string, path: string, line: int}  $found
     */
    public static function code(array $found, ?string $patch): ?string
    {
        foreach (PatchSummary::files($patch) as $file) {
            if ($file['path'] !== $found['path']) {
                continue;
            }

            foreach (PatchSummary::addedLines($file['diff']) as $line) {
                if ($line['line'] === $found['line']) {
                    return $line['text'];
                }
            }
        }

        return null;
    }

    /**
     * Determine if a later patch dealt with a shortcut whose line held the
     * code: it removed that line, or added a comment to the file saying
     * why it is right.
     */
    public static function addressed(string $path, string $code, ?string $patch): bool
    {
        foreach (PatchSummary::files($patch) as $file) {
            if ($file['path'] !== $path) {
                continue;
            }

            foreach (explode("\n", $file['diff']) as $line) {
                if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                    continue;
                }

                if ($line === '-'.$code) {
                    return true;
                }

                if (str_starts_with($line, '+') && (preg_match(self::COMMENT_LINE, substr($line, 1)) === 1 || preg_match(self::COMMENT_END, substr($line, 1)) === 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Say what a shortcut costs the owner, as a statement about its line.
     */
    public static function concern(string $rule): string
    {
        return 'This line '.self::RULES[$rule]['problem'].'.';
    }

    /**
     * Say what the shortcut costs and how to avoid it, for the coder that
     * must fix it.
     *
     * @param  array{rule: string, path: string, line: int}  $found
     */
    public static function finding(array $found): string
    {
        $rule = self::RULES[$found['rule']];

        return __('Line :line of :path :problem. :fix If it is right as it is, say why in a comment on the line below.', [
            'line' => $found['line'],
            'path' => $found['path'],
            'problem' => $rule['problem'],
            'fix' => $rule['fix'],
        ]);
    }
}
