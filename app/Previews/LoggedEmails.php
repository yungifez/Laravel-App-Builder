<?php

namespace App\Previews;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Email an app wrote to its log instead of sending it. Laravel's log mailer
 * writes each email whole, as a debug entry, with its quoted-printable parts
 * already decoded.
 */
class LoggedEmails
{
    /**
     * The start of the entry that marks emails as deleted. The log keeps
     * each email; the list leaves out those a later entry marks.
     */
    public const DELETED = 'Emails deleted: ';

    /**
     * Find the emails in a log, newest first, leaving out deleted ones.
     *
     * @return list<array{id: string, sent_at: string|null, from: string, to: string, subject: string, html: string|null, text: string|null}>
     */
    public static function in(string $log, int $limit = 50): array
    {
        $emails = [];

        foreach (LogEntries::in($log) as $entry) {
            if ($entry['level'] === 'DEBUG' && ($email = self::read($entry['message'], $entry['time'])) !== null) {
                $emails[] = $email;
            }
        }

        $deleted = self::deleted($log);
        $emails = array_filter($emails, fn (array $email) => ! isset($deleted[$email['id']]));

        return array_slice(array_reverse($emails), 0, $limit);
    }

    /**
     * Get what the log marks as deleted, by id.
     *
     * @return array<string, true>
     */
    public static function deleted(string $log): array
    {
        $deleted = [];

        foreach (LogEntries::in($log) as $entry) {
            if ($entry['level'] === 'INFO' && str_starts_with($entry['message'], self::DELETED)) {
                $deleted += array_fill_keys(explode(' ', substr($entry['message'], strlen(self::DELETED))), true);
            }
        }

        return $deleted;
    }

    /**
     * Write the log entry that marks emails as deleted.
     *
     * @param  list<string>  $ids
     */
    public static function deletion(array $ids, CarbonImmutable $at): string
    {
        return '['.$at->format('Y-m-d H:i:s').'] local.INFO: '.self::DELETED.implode(' ', $ids).PHP_EOL;
    }

    /**
     * Read one log entry as an email, or null when it is not one.
     *
     * @return array{id: string, sent_at: string|null, from: string, to: string, subject: string, html: string|null, text: string|null}|null
     */
    protected static function read(string $message, string $time): ?array
    {
        [$headers, $body] = self::split(str_replace("\r\n", "\n", $message));

        if (! isset($headers['mime-version']) || ! (isset($headers['to']) || isset($headers['subject']))) {
            return null;
        }

        $content = ['html' => null, 'text' => null];
        self::collect($headers, $body, $content);

        return [
            'id' => sha1($time."\n".($headers['message-id'] ?? $message)),
            'sent_at' => rescue(fn () => CarbonImmutable::parse($time)->toIso8601String(), null, report: false),
            'from' => $headers['from'] ?? '',
            'to' => $headers['to'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'html' => $content['html'],
            'text' => $content['text'],
        ];
    }

    /**
     * Split a part into its headers, by lower-case name, and its body.
     *
     * @return array{array<string, string>, string}
     */
    protected static function split(string $part): array
    {
        [$head, $body] = array_pad(explode("\n\n", $part, 2), 2, '');
        $headers = [];
        $name = null;

        foreach (explode("\n", $head) as $line) {
            // A long header goes on in lines that start with a space.
            if ($name !== null && preg_match('/^[ \t]/', $line) === 1) {
                $headers[$name] .= ' '.trim($line);

                continue;
            }

            if (preg_match('/^([\w-]+):\s?(.*)$/', $line, $match) !== 1) {
                // Not a header block: this entry is not an email.
                return [[], $part];
            }

            $name = strtolower($match[1]);
            $headers[$name] = $match[2];
        }

        return [array_map(self::decode(...), $headers), $body];
    }

    /**
     * Take the first HTML and plain text bodies, looking inside nested
     * parts and leaving out attachments.
     *
     * @param  array<string, string>  $headers
     * @param  array{html: string|null, text: string|null}  $content
     */
    protected static function collect(array $headers, string $body, array &$content): void
    {
        $type = strtolower(trim(strtok($headers['content-type'] ?? 'text/plain', ';') ?: 'text/plain'));

        if (str_starts_with($type, 'multipart/')) {
            if (preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'] ?? '', $match) !== 1) {
                return;
            }

            $sections = explode('--'.$match[1], $body);

            // Before the first boundary is a preamble; after the last one
            // ("--boundary--") is nothing that shows.
            foreach (array_slice($sections, 1) as $section) {
                if (str_starts_with($section, '--')) {
                    break;
                }

                [$partHeaders, $partBody] = self::split(ltrim($section, "\n"));
                self::collect($partHeaders, $partBody, $content);
            }

            return;
        }

        if (str_starts_with(strtolower($headers['content-disposition'] ?? ''), 'attachment')) {
            return;
        }

        $key = match ($type) {
            'text/html' => 'html',
            'text/plain' => 'text',
            default => null,
        };

        if ($key === null || $content[$key] !== null) {
            return;
        }

        $decoded = strtolower(trim($headers['content-transfer-encoding'] ?? '')) === 'base64'
            ? (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true)
            : $body;

        $content[$key] = rtrim($decoded, "\n");
    }

    /**
     * Decode words written in another character set, as in a subject line.
     */
    protected static function decode(string $value): string
    {
        try {
            return trim(mb_decode_mimeheader($value));
        } catch (Throwable) {
            return trim($value);
        }
    }
}
