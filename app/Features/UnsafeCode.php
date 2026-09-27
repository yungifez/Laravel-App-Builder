<?php

namespace App\Features;

/**
 * Common safety mistakes on the lines a change adds, found by pattern alone:
 * text shown on a page without escaping it, database queries built from
 * values, records open to every field of a form, and secret settings kept
 * in the app's history. Only added lines count, so code the app already
 * had (a starter kit's own QR code, say) is never held against a change.
 *
 * A line is let through when it, or the line above it, carries a comment
 * that says why it is safe: a reason a person can read and question.
 */
class UnsafeCode
{
    /**
     * Each rule: the files it reads, the pattern it finds, what is wrong,
     * and the safe way to do it.
     *
     * @var array<string, array{files: string, pattern: string, problem: string, fix: string}>
     */
    protected const RULES = [
        'unescaped_output' => [
            'files' => '/\.blade\.php$/',
            'pattern' => '/\{!!/',
            'problem' => 'shows text on a page without escaping it ({!! !!}), so people could put their own code on the page',
            'fix' => 'Use {{ }}, which escapes it.',
        ],
        'raw_html' => [
            'files' => '/\.vue$/',
            'pattern' => '/\bv-html\s*=/',
            'problem' => 'shows HTML on a page as it is (v-html), so people could put their own code on the page',
            'fix' => 'Show it as text with {{ }}, or build the markup in the template.',
        ],
        'raw_query' => [
            'files' => '/^(?!tests\/).*\.php$/',
            'pattern' => '/(?:Raw|DB::(?:select|statement|unprepared|insert|update|delete))\(\s*(?:"[^"]*\$|\'[^\']*\'\s*\.|\$)/',
            'problem' => 'builds a database query from values mixed into its text, so people could change what the query does',
            'fix' => 'Pass the values as bindings (? placeholders with an array), or use the query builder.',
        ],
        'open_fields' => [
            'files' => '/^(?!tests\/).*\.php$/',
            'pattern' => '/\$guarded\s*=\s*\[\s*\]|::unguard\(|#\[Unguarded/',
            'problem' => 'lets a record take any field it is given, so people could set fields they should not',
            'fix' => 'Name the fields it may take with $fillable or #[Fillable].',
        ],
        'secret_settings' => [
            'files' => '/(^|\/)\.env(?!\.example$)(\.[\w.-]+)?$/',
            'pattern' => '/./',
            'problem' => 'keeps secret settings (.env) in the app\'s history, where anyone with the code can read them',
            'fix' => 'Remove the file from the change, and list the setting names in .env.example instead.',
        ],
    ];

    /**
     * A comment that says why a line is safe.
     */
    protected const SAFE_COMMENT = '/(\/\/|#|\/\*|\{\{--|<!--).*\bsafe\b/i';

    /**
     * Find the mistakes on the lines the patch adds, numbered as in the new
     * file. A file with many is named once per rule, at its first line.
     *
     * @return list<array{rule: string, path: string, line: int}>
     */
    public static function found(?string $patch): array
    {
        $found = [];

        foreach (PatchSummary::files($patch) as $file) {
            $rules = array_filter(self::RULES, fn (array $rule) => preg_match($rule['files'], $file['path']) === 1);

            if ($rules === [] || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            $inHunk = false;
            $number = 0;
            $previous = '';

            foreach (explode("\n", $file['diff']) as $line) {
                if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $match) === 1) {
                    $inHunk = true;
                    $number = (int) $match[1] - 1;
                    $previous = '';

                    continue;
                }

                // File headers, removed lines and "no newline" notes are not in the new file.
                if (! $inHunk || str_starts_with($line, '-') || str_starts_with($line, '\\')) {
                    continue;
                }

                $number++;
                $text = substr($line, 1);

                if (str_starts_with($line, '+') && preg_match(self::SAFE_COMMENT, $text.' '.$previous) !== 1) {
                    foreach ($rules as $key => $rule) {
                        if (! isset($found[$file['path'].$key]) && preg_match($rule['pattern'], $text) === 1) {
                            $found[$file['path'].$key] = ['rule' => $key, 'path' => $file['path'], 'line' => $number];
                        }
                    }
                }

                $previous = $text;
            }
        }

        return array_values($found);
    }

    /**
     * Say what is wrong with a found mistake and how to fix it, for the
     * coder that must fix it.
     *
     * @param  array{rule: string, path: string, line: int}  $found
     */
    public static function finding(array $found): string
    {
        $rule = self::RULES[$found['rule']];

        return __('Line :line of :path :problem. :fix If it is safe as it is, say why in a comment on that line or the line above.', [
            'line' => $found['line'],
            'path' => $found['path'],
            'problem' => $rule['problem'],
            'fix' => $rule['fix'],
        ]);
    }

    /**
     * Determine if the patch changes code the rules read, so a clean scan
     * says something.
     */
    public static function scans(?string $patch): bool
    {
        foreach (PatchSummary::files($patch) as $file) {
            foreach (self::RULES as $key => $rule) {
                if ($key !== 'secret_settings' && preg_match($rule['files'], $file['path']) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
