<?php

namespace App\Previews;

/**
 * The entries of a Laravel log, oldest first. Each starts on a new line
 * with "[time] channel.LEVEL: " and runs over as many lines as it needs.
 */
class LogEntries
{
    /**
     * Split a log into its entries. Anything before the first one is the
     * end of an entry cut off by a partial read, and is left out.
     *
     * @return list<array{time: string, level: string, message: string}>
     */
    public static function in(string $log): array
    {
        $pieces = preg_split('/^\[([^\]\n]+)\] [\w-]+\.(\w+): /m', $log, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $entries = [];

        for ($at = 1; $at + 2 < count($pieces); $at += 3) {
            $entries[] = ['time' => $pieces[$at], 'level' => $pieces[$at + 1], 'message' => rtrim($pieces[$at + 2])];
        }

        return $entries;
    }
}
