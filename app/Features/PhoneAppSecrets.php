<?php

namespace App\Features;

/**
 * Finds secrets in a phone app's settings. A NativePHP app carries its
 * settings file inside the app, where anyone who downloads it can read
 * them, so a key, token or password there is given away. The phone gets
 * its own token when the person signs in instead.
 */
class PhoneAppSecrets
{
    /**
     * Setting names that hold a secret.
     */
    protected const SECRET = '/(^|_)(KEY|SECRET|TOKEN|PASSWORD|PASSPHRASE|PRIVATE|CREDENTIALS?)(_|$)/i';

    /**
     * Values that hold nothing.
     */
    protected const EMPTY = ['', 'null', '""', "''", '(null)'];

    /**
     * Get the names of the settings that give a secret away, in file order.
     *
     * @return list<string>
     */
    public static function find(string $settings): array
    {
        $found = [];

        foreach (preg_split('/\R/', $settings) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $value = trim((string) preg_replace('/(^|\s+)#.*$/', '', $match[2]));

            if (preg_match(self::SECRET, $match[1]) === 1 && ! in_array(strtolower($value), self::EMPTY, true)) {
                $found[] = $match[1];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * What the coding agent reads when the check fails.
     *
     * @param  list<string>  $names
     */
    public static function explain(array $names): string
    {
        return __('A phone app carries .env.example inside the app, where anyone who downloads it can read it. Leave these empty: :names. The phone gets its own token from the app it talks to when the person signs in, and keeps it in the phone.', ['names' => implode(', ', $names)]);
    }
}
