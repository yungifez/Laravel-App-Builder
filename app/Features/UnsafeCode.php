<?php

namespace App\Features;

/**
 * Common safety mistakes on the lines a change adds, found by pattern alone:
 * text shown on a page without escaping it, database queries built from
 * values, records open to every field of a form, and secret settings or
 * keys kept in the app's history. Only added lines count, so code the app
 * already had (a starter kit's own QR code, say) is never held against a
 * change.
 *
 * A line is let through when it, or the line above it, carries a comment
 * that says why it is safe: a reason a person can read and question. A
 * secret key never is.
 */
class UnsafeCode
{
    /**
     * Each rule: the files it reads, the pattern it finds, what is wrong,
     * and the safe way to do it.
     *
     * @var array<string, array{files: string, pattern: string, problem: string, fix: string, always?: bool}>
     */
    protected const RULES = [
        'unescaped_output' => [
            'files' => '/\.blade\.php$/',
            'pattern' => '/\{!!/',
            'problem' => 'shows text on a page without escaping it ({!! !!}), so people could put their own code on the page',
            'fix' => 'Use {{ }}, which escapes it.',
        ],
        // Each frontend's way to show HTML as it is: Vue's v-html, Alpine's
        // x-html (in Livewire and Blade views), React's
        // dangerouslySetInnerHTML and Svelte's {@html}.
        'raw_html' => [
            'files' => '/\.(vue|blade\.php|jsx|tsx|svelte)$/',
            'pattern' => '/\b[vx]-html\s*=|\bdangerouslySetInnerHTML\b|\{@html\b/',
            'problem' => 'shows HTML on a page as it is, so people could put their own code on the page',
            'fix' => 'Show it as text, which escapes it, or build the markup in the template.',
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
        // A live key written into the code. No comment lets it through: a
        // key in the code is readable by anyone with the code, whatever the
        // line says about it.
        'secret_in_code' => [
            'files' => '/^(?!(.*\/)?\.env(\.|$))/',
            'pattern' => '/\b(?:sk_live_[0-9A-Za-z]{16,}|rk_live_[0-9A-Za-z]{16,}|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,}|xox[abprs]-[A-Za-z0-9-]{20,}|sk-ant-[A-Za-z0-9_-]{32,}|sk-(?:proj-)?[A-Za-z0-9_-]{40,}|AIza[0-9A-Za-z_-]{35}|SG\.[\w-]{22}\.[\w-]{43})|-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/',
            'problem' => 'writes a secret key into the code, where anyone with the code can read it and use it',
            'fix' => 'Read it from a setting instead: config() in the code, env() in a file under config/, and the setting name with no value in .env.example.',
            'always' => true,
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

            foreach (PatchSummary::addedLines($file['diff']) as $added) {
                $commented = preg_match(self::SAFE_COMMENT, $added['text'].' '.$added['previous']) === 1;

                foreach ($rules as $key => $rule) {
                    if ($commented && ! ($rule['always'] ?? false)) {
                        continue;
                    }

                    if (! isset($found[$file['path'].$key]) && preg_match($rule['pattern'], $added['text']) === 1) {
                        $found[$file['path'].$key] = ['rule' => $key, 'path' => $file['path'], 'line' => $added['line']];
                    }
                }
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

        if ($rule['always'] ?? false) {
            return __('Line :line of :path :problem. :fix', ['line' => $found['line'], 'path' => $found['path'], 'problem' => $rule['problem'], 'fix' => $rule['fix']]);
        }

        return __('Line :line of :path :problem. :fix If it is safe as it is, say why in a comment on that line or the line above.', [
            'line' => $found['line'],
            'path' => $found['path'],
            'problem' => $rule['problem'],
            'fix' => $rule['fix'],
        ]);
    }

    /**
     * Get the files among the app's that keep secret settings (.env), which
     * anyone with the code can read.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function secretFiles(array $files): array
    {
        return array_values(array_filter($files, fn (string $path) => preg_match(self::RULES['secret_settings']['files'], $path) === 1));
    }

    /**
     * Determine if the patch changes code the rules read, so a clean scan
     * says something.
     */
    public static function scans(?string $patch): bool
    {
        foreach (PatchSummary::files($patch) as $file) {
            foreach (self::RULES as $key => $rule) {
                if (! in_array($key, ['secret_settings', 'secret_in_code'], true) && preg_match($rule['files'], $file['path']) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
