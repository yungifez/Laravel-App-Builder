<?php

namespace App\Features;

use Illuminate\Support\Str;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * The emails and text messages a change adds, read from its code
 * (architecture §12). A new message reaches real people once the app is
 * published, so the owner approves it before the change is kept. Previews
 * never send: they use the log mailer.
 */
class NewMessages
{
    /**
     * A new email or text message the owner has not approved yet.
     */
    public const UNAPPROVED = 'message_unapproved';

    /**
     * The kinds the owner may approve.
     */
    public const OWNED = [self::UNAPPROVED];

    /**
     * What a notification's channel sends, by the name via() gives it or
     * the start of the method that builds the message.
     */
    protected const CHANNELS = [
        'mail' => 'mail',
        'vonage' => 'sms',
        'nexmo' => 'sms',
        'twilio' => 'sms',
        'sms' => 'sms',
        'messagebird' => 'sms',
        'sns' => 'sms',
    ];

    /**
     * Find the classes in one PHP file that send an email or a text
     * message, and how. Empty when the file cannot be read as PHP.
     *
     * A mailable sends an email. A notification sends on the channels its
     * via() names; when via() names none plainly, its toMail() and similar
     * methods say. A notification that only saves or broadcasts sends
     * nothing to anyone.
     *
     * @return list<array{class: string, channels: list<string>, at: string}>
     */
    public static function read(string $path, string $code): array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        $ast = (new NodeTraverser(new NameResolver))->traverse($ast);
        $found = [];

        foreach ((new NodeFinder)->findInstanceOf($ast, Class_::class) as $class) {
            if ($class->isAbstract()) {
                continue;
            }

            $channels = match ($class->extends?->toString()) {
                'Illuminate\Mail\Mailable' => ['mail'],
                'Illuminate\Notifications\Notification' => self::channels($class),
                default => [],
            };

            if ($channels !== []) {
                $found[] = [
                    'class' => $class->namespacedName?->toString() ?? (string) $class->name,
                    'channels' => $channels,
                    'at' => "{$path}:{$class->getStartLine()}",
                ];
            }
        }

        return $found;
    }

    /**
     * Find the messages a patch adds, given a reader of each file as the
     * change leaves it: each class in a file it adds that sends, or one it
     * changes that now sends on a channel it did not before. Tests and
     * deleted files send nothing. A changed file whose earlier text cannot
     * be rebuilt is not guessed at.
     *
     * @param  callable(string): ?string  $contents
     * @return list<array{class: string, channels: list<string>, at: string}>
     */
    public static function inPatch(?string $patch, callable $contents): array
    {
        $found = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (! str_ends_with($file['path'], '.php') || str_starts_with($file['path'], 'tests/') || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            $code = $contents($file['path']);

            if (! is_string($code)) {
                continue;
            }

            $added = str_contains($file['diff'], "\nnew file mode ") || str_contains($file['diff'], "\n--- /dev/null");
            $old = $added ? null : PatchSummary::before($code, $file['diff']);

            if (! $added && $old === null) {
                continue;
            }

            $before = $old === null ? [] : array_column(self::read($file['path'], $old), 'channels', 'class');

            foreach (self::read($file['path'], $code) as $message) {
                if (array_diff($message['channels'], $before[$message['class']] ?? []) !== []) {
                    $found[] = $message;
                }
            }
        }

        return $found;
    }

    /**
     * Get the findings: each new message the owner has not approved.
     *
     * @param  list<array{class: string}>|null  $messages
     * @param  list<string>  $accepted  Identities the owner approved
     * @return list<array{kind: string, subject: string}>
     */
    public static function findings(?array $messages, array $accepted = []): array
    {
        $findings = array_map(fn (array $message) => ['kind' => self::UNAPPROVED, 'subject' => $message['class']], $messages ?? []);

        return array_values(array_filter($findings, fn (array $finding) => ! in_array(self::identity($finding), $accepted, true)));
    }

    /**
     * Name a finding the same way each time the checks run.
     *
     * @param  array{kind: string, subject: string}  $finding
     */
    public static function identity(array $finding): string
    {
        return "{$finding['kind']}|{$finding['subject']}";
    }

    /**
     * Name a message for the owner: "invoice paid" for
     * App\Notifications\InvoicePaidNotification.
     */
    public static function name(string $class): string
    {
        $name = Str::replaceEnd('Mail', '', Str::replaceEnd('Notification', '', class_basename($class)));

        return Str::lower(Str::headline($name === '' ? class_basename($class) : $name));
    }

    /**
     * Read the channels a notification sends an email or text message on.
     *
     * @return list<string>
     */
    protected static function channels(Class_ $class): array
    {
        $via = $class->getMethod('via');
        $named = [];

        foreach ($via === null ? [] : (new NodeFinder)->findInstanceOf($via->stmts ?? [], Return_::class) as $return) {
            if (! $return->expr instanceof Array_) {
                continue;
            }

            foreach ($return->expr->items as $item) {
                $named[] = self::channel($item->value);
            }
        }

        // via() names its channels plainly: they are the whole answer.
        if ($named !== [] && ! in_array(null, $named, true)) {
            return self::known(array_map(strval(...), $named));
        }

        return self::known(array_map(fn ($method) => Str::after($method->name->toString(), 'to'), array_filter($class->getMethods(), fn ($method) => str_starts_with($method->name->toString(), 'to'))));
    }

    /**
     * Name one channel via() gives: "mail", or "vonage" for a channel
     * class such as VonageSmsChannel. Null when it is not written plainly.
     */
    protected static function channel(Node $value): ?string
    {
        if ($value instanceof String_) {
            return $value->value;
        }

        if ($value instanceof ClassConstFetch && $value->class instanceof Node\Name) {
            return Str::before(class_basename($value->class->toString()), 'Channel');
        }

        return null;
    }

    /**
     * Keep the channels that send an email or a text message, as "mail"
     * or "sms", once each.
     *
     * @param  array<string>  $names
     * @return list<string>
     */
    protected static function known(array $names): array
    {
        $kinds = [];

        foreach ($names as $name) {
            foreach (self::CHANNELS as $start => $kind) {
                if (str_starts_with(Str::lower($name), $start)) {
                    $kinds[] = $kind;

                    break;
                }
            }
        }

        return array_values(array_unique($kinds));
    }
}
