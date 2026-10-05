<?php

namespace App\Support;

/**
 * Live keys and tokens in text. The code checks use it to refuse a key
 * written into the app, and the model calls use it to keep keys out of
 * what is sent: an owner may paste a key into a request or a note.
 */
class Secrets
{
    /**
     * Keys with a known shape, and the first line of a private key.
     */
    public const PATTERN = '/\b(?:sk_live_[0-9A-Za-z]{16,}|rk_live_[0-9A-Za-z]{16,}|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,}|xox[abprs]-[A-Za-z0-9-]{20,}|sk-ant-[A-Za-z0-9_-]{32,}|sk-(?:proj-)?[A-Za-z0-9_-]{40,}|AIza[0-9A-Za-z_-]{35}|SG\.[\w-]{22}\.[\w-]{43})|-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/';

    /**
     * A whole private key, from its first line to its last.
     */
    protected const PRIVATE_KEY = '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----.*?(?:-----END (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----|$)/s';

    public const REMOVED = '[secret removed]';

    /**
     * Determine if the text holds a live key.
     */
    public static function found(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }

    /**
     * Replace each live key in the text, so the rest can still be read.
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace([self::PRIVATE_KEY, self::PATTERN], self::REMOVED, $text);
    }
}
