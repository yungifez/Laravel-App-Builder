<?php

namespace App\Features;

use App\Support\Secrets;
use Illuminate\Support\Str;

/**
 * Common safety mistakes on the lines a change adds, found by pattern alone:
 * text shown on a page without escaping it, database queries built from
 * values, records open to every field of a form, secret settings or keys
 * kept in the app's history, secret settings sent to the browser, and
 * redirects or files whose address comes from the request. Only added
 * lines count, so code the app
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
     * @var array<string, array{files: string, pattern: string, problem: string, fix: string, always?: bool, within?: string, named?: bool, choices?: bool}>
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
            'pattern' => Secrets::PATTERN,
            'problem' => 'writes a secret key into the code, where anyone with the code can read it and use it',
            'fix' => 'Read it from a setting instead: config() in the code, env() in a file under config/, and the setting name with no value in .env.example.',
            'always' => true,
        ],
        // Vite puts every VITE_ setting the code reads into the built
        // JavaScript, so its value reaches everyone who opens a page. The
        // settings made for browsers are in builder.verification
        // .browser_settings. No comment lets a secret through.
        'secret_to_browser' => [
            'files' => '/^(?!tests\/)(.*(^|\/)\.env\.example$|config\/.*\.php$|.*\.(vue|js|mjs|jsx|ts|tsx|svelte)$)/',
            'pattern' => '/\bVITE_(?<name>(?:\w*_)?(?:KEY|SECRET|TOKEN|PASSWORD|PASSPHRASE|PRIVATE|CREDENTIALS?)(?:_\w*)?)\b/',
            'problem' => 'gives a secret setting a VITE_ name, so its value is built into the JavaScript every visitor downloads',
            'fix' => 'Keep the secret on the server without the VITE_ prefix, and let the page reach what it needs through a route of the app.',
            'always' => true,
            'named' => true,
        ],
        // Shared Inertia props and Blade views go to every page they render.
        'secret_to_page' => [
            'files' => '/^(?!tests\/)(.*(^|\/)HandleInertiaRequests\.php$|.*\.blade\.php$)/',
            'pattern' => '/'.self::SECRET_READ.'/',
            'problem' => 'puts a secret setting on the page, where anyone who opens it can read it',
            'fix' => 'Keep the secret on the server, and let the page reach what it needs through a route of the app.',
            'always' => true,
            'named' => true,
        ],
        // A page's own props: a value inside the Inertia::render() call. A
        // secret passed to a client on the server, beside it, is not one.
        'secret_to_props' => [
            'files' => '/^(?!tests\/).*Controllers\/.*\.php$/',
            'pattern' => '/=>\s*'.self::SECRET_READ.'/',
            'problem' => 'puts a secret setting in a page\'s props, where anyone who opens the page can read it',
            'fix' => 'Keep the secret on the server, and let the page reach what it needs through a route of the app.',
            'always' => true,
            'within' => '/\bInertia::render\(|\binertia\(/',
            'named' => true,
        ],
        // A redirect whose address is a value from the request. A route, the
        // address the person meant (intended()), back() and the previous
        // address are the app's own, so they are never matched.
        'open_redirect' => [
            'files' => '/^(?!tests\/).*\.php$/',
            'pattern' => '/(?J)\b(?:redirect\(\)->(?:to|away)|redirect|Redirect::(?:to|away)|Inertia::location)\(\s*(?:[\'"](?:https?:)?\/\/[\'"]\s*\.\s*|"(?:https?:)?\/\/\{?)?'.self::FROM_REQUEST.'/',
            'problem' => 'sends people to an address taken from the request, so a link to the app could send them to any site',
            'fix' => 'Send them to a route of the app (redirect()->route()), to redirect()->intended() or back(), or allow only known values with an in: rule.',
            'always' => true,
            'choices' => true,
        ],
        // A file found by a path from the request. A path wrapped in
        // basename() stays in its folder, so it is never matched.
        'path_from_request' => [
            'files' => '/^(?!tests\/).*\.php$/',
            'pattern' => '/(?J)\b(?:Storage::(?:disk\([^)]*\)->)?(?:download|get|response|path|readStream|delete|url)|response\(\)->(?:download|file)|file_get_contents|readfile|fopen|unlink|File::(?:get|delete))\(\s*(?:(?:storage|public|base|resource)_path\(\s*)?(?:[^;()]*?\.\s*|"[^"]*?\{?)?'.self::FROM_REQUEST.'/',
            'problem' => 'reads or removes a file whose path comes from the request, so people could reach any file, such as .env',
            'fix' => 'Find the file through a record the person may reach (its stored path), wrap the name in basename(), or allow only known names with an in: rule.',
            'always' => true,
            'choices' => true,
        ],
    ];

    /**
     * A value from the request, with the name of its field (key) where the
     * code names it. Who is signed in, the route and uploads are not.
     */
    protected const FROM_REQUEST = '(?:\$request->(?:input|query|get|post|string|str|validated|header)\(\s*[\'"](?<key>[\w.-]+)|\$request->(?!user\b|route\b|file\b|ip\b)(?<key>\w+)\b(?!\s*\()|request\(\s*[\'"](?<key>[\w.-]+)|request\(\)->\w+\(\s*[\'"](?<key>[\w.-]+)|Request::(?:input|query|get)\(\s*[\'"](?<key>[\w.-]+)|\$_(?:GET|POST|REQUEST)\[\s*[\'"](?<key>[\w.-]+))';

    /**
     * Reading a secret setting: a service's secret, key, token or password,
     * the app's own key, or an env() setting named like one.
     */
    protected const SECRET_READ = '(?:config\(\s*[\'"](?<config>services\.[\w-]+\.(?:[\w-]+\.)*(?:secret|token|password|key|client_secret|webhook_secret|api_key)|app\.key)[\'"]|env\(\s*[\'"](?<name>\w*(?:KEY|SECRET|TOKEN|PASSWORD|PASSPHRASE)\w*)[\'"])';

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

        // Fields the change allows only some values for, with an in: rule or
        // Rule::in(), in a form request or a validate() call it added.
        $everyAdded = implode("\n", array_merge([], ...array_map(fn (array $file) => array_column(PatchSummary::addedLines($file['diff']), 'text'), PatchSummary::files($patch))));
        $chosen = fn (string $key) => preg_match('/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>.*(?:[\'"|]in:|Rule::in\()/', $everyAdded) === 1;

        foreach (PatchSummary::files($patch) as $file) {
            $rules = array_filter(self::RULES, fn (array $rule) => preg_match($rule['files'], $file['path']) === 1);

            if ($rules === [] || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            $inside = array_map(fn (array $rule) => isset($rule['within']) ? self::inside($file['diff'], $rule['within']) : null, $rules);

            foreach (PatchSummary::addedLines($file['diff']) as $added) {
                $commented = preg_match(self::SAFE_COMMENT, $added['text'].' '.$added['previous']) === 1;

                foreach ($rules as $key => $rule) {
                    if (($commented && ! ($rule['always'] ?? false)) || (($inside[$key] ?? null) !== null && ! isset($inside[$key][$added['line']]))) {
                        continue;
                    }

                    if (! isset($found[$file['path'].$key]) && preg_match($rule['pattern'], $added['text'], $match) === 1 && ! (($rule['named'] ?? false) && self::forBrowsers($match)) && ! (($rule['choices'] ?? false) && ($match['key'] ?? '') !== '' && $chosen($match['key']))) {
                        $found[$file['path'].$key] = ['rule' => $key, 'path' => $file['path'], 'line' => $added['line']];
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * Get the lines of the new file, numbered as in it, that are inside a
     * call the pattern opens, by counting its brackets over the lines the
     * diff shows. A call that opened before the hunk is not seen.
     *
     * @return array<int, true>
     */
    protected static function inside(string $diff, string $opens): array
    {
        $inside = [];
        $depth = 0;
        $number = 0;
        $inHunk = false;

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $match) === 1) {
                [$inHunk, $number, $depth] = [true, (int) $match[1] - 1, 0];

                continue;
            }

            if (! $inHunk || str_starts_with($line, '-') || str_starts_with($line, '\\')) {
                continue;
            }

            $number++;
            $text = substr($line, 1);

            if (preg_match($opens, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                $text = substr($text, $match[0][1]);
                $inside[$number] = true;
            } elseif ($depth > 0) {
                $inside[$number] = true;
            } else {
                continue;
            }

            $depth = max(0, $depth + substr_count($text, '(') + substr_count($text, '[') - substr_count($text, ')') - substr_count($text, ']'));
        }

        return $inside;
    }

    /**
     * Determine if a setting a line sends to the browser is one made to be
     * read there, by its name without the VITE_ prefix or its config key.
     *
     * @param  array<int|string, string>  $match
     */
    protected static function forBrowsers(array $match): bool
    {
        $name = ($match['config'] ?? '') !== '' ? $match['config'] : Str::chopStart($match['name'] ?? '', 'VITE_');

        return Str::is((array) config('builder.verification.browser_settings', []), $name);
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
