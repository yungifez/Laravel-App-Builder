<?php

namespace App\Features;

use App\Context\Capability;

/**
 * Small mistakes made on purpose in a change's new code (§12, direction
 * 33). A test that fails without the change can still check nothing about
 * its logic: a new page is missing without the change, so any test of it
 * fails there. Turning one condition around, or leaving out one call, and
 * running the tests that run that line shows whether they notice. A
 * mistake no test notices marks a line whose behaviour no test pins down.
 *
 * Each line gets at most one mistake, chosen by fixed rules from PHP's own
 * tokens, so the same change always gets the same mistakes, and strings
 * and comments are never touched.
 */
class Mutants
{
    /**
     * Operators turned into their opposite, by token.
     */
    protected const OPPOSITES = [
        T_IS_IDENTICAL => '!==',
        T_IS_NOT_IDENTICAL => '===',
        T_IS_EQUAL => '!=',
        T_IS_NOT_EQUAL => '==',
        T_IS_GREATER_OR_EQUAL => '<',
        T_IS_SMALLER_OR_EQUAL => '>',
        T_BOOLEAN_AND => '||',
        T_BOOLEAN_OR => '&&',
    ];

    /**
     * Calls whose line is left out: the ones that refuse, save or send.
     * Leaving out what decides who may act is the mistake a test of a
     * refusal must notice.
     */
    protected const LEFT_OUT = '/^(\$this->authorize|Gate::(authorize|denyIf|allowIf)|abort(_if|_unless)?\(|\$[a-z_][a-z0-9_]*->(save|update|delete|create|attach|detach|sync|notify)\(|[A-Z][A-Za-z0-9_]*::(dispatch|create)\(|(Mail|Notification|Event|Bus)::|(dispatch|event)\()/i';

    /**
     * Choose the mistakes for the PHP lines a patch adds outside the tests,
     * at most $max, spread over the files in a fixed order.
     *
     * @param  callable(string, int): bool  $run  Whether a test runs the given line
     * @param  (callable(string): bool)|null  $isTest  Whether a file is a test the suite runs
     * @return list<array{file: string, line: int, was: string, now: string}>
     */
    public static function choose(?string $patch, callable $run, int $max, ?callable $isTest = null): array
    {
        $isTest ??= Capability::runBySuite(...);
        $byFile = [];

        foreach (PatchSummary::files((string) $patch) as $file) {
            if (! str_ends_with($file['path'], '.php') || $isTest($file['path']) || str_starts_with($file['path'], 'database/')) {
                continue;
            }

            foreach (PatchSummary::addedLines($file['diff']) as $added) {
                $now = self::mistake($added['text']);

                if ($now !== null && $run($file['path'], $added['line'])) {
                    $byFile[$file['path']][] = ['file' => $file['path'], 'line' => $added['line'], 'was' => $added['text'], 'now' => $now];
                }
            }
        }

        ksort($byFile);
        $chosen = [];

        // One from each file in turn, so one long file does not use them all.
        while (count($chosen) < $max && $byFile !== []) {
            foreach ($byFile as $path => $mutants) {
                $chosen[] = array_shift($byFile[$path]);

                if ($byFile[$path] === []) {
                    unset($byFile[$path]);
                }

                if (count($chosen) === $max) {
                    break;
                }
            }
        }

        return $chosen;
    }

    /**
     * Make one mistake in a line of PHP, or null when the rules have none
     * for it. A call that refuses, saves or sends is left out; otherwise
     * the first comparison or logical operator is turned around, then a
     * `true` or `false`, then a `!` is dropped.
     */
    public static function mistake(string $line): ?string
    {
        $code = trim($line);

        if ($code === '' || preg_match('#^(//|\*|/\*|\#)#', $code) === 1) {
            return null;
        }

        if (preg_match(self::LEFT_OUT, $code) === 1 && str_ends_with($code, ';')) {
            return '';
        }

        $tokens = token_get_all('<?php '.$line);
        array_shift($tokens);

        foreach ([self::opposite(...), self::flipped(...), self::dropped(...)] as $rule) {
            foreach ($tokens as $index => $token) {
                $replacement = $rule($token);

                if ($replacement !== null) {
                    $tokens[$index] = $replacement;

                    return implode('', array_map(fn ($token) => is_array($token) ? $token[1] : $token, $tokens));
                }
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    protected static function opposite(array|string $token): ?string
    {
        return match (true) {
            is_array($token) => self::OPPOSITES[$token[0]] ?? null,
            $token === '>' => '<=',
            $token === '<' => '>=',
            default => null,
        };
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    protected static function flipped(array|string $token): ?string
    {
        if (! is_array($token) || $token[0] !== T_STRING) {
            return null;
        }

        return match (strtolower($token[1])) {
            'true' => 'false',
            'false' => 'true',
            default => null,
        };
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    protected static function dropped(array|string $token): ?string
    {
        return $token === '!' ? '' : null;
    }
}
