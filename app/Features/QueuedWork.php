<?php

namespace App\Features;

use Illuminate\Support\Str;
use PhpParser\Error;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Work a change sends to the queue, read from its code (architecture §12).
 * A job, queued listener, mailable or notification that does not say how
 * often to try again, how long to wait between tries and what to do when
 * it gives up fails quietly on the live app: one try with the worker's
 * defaults, then nothing tells anyone.
 */
class QueuedWork
{
    /**
     * Queued work that leaves out how it tries again or fails.
     */
    public const UNGUARDED = 'queued_unguarded';

    /**
     * The kinds the owner may say they want, such as work that must run
     * once only.
     */
    public const OWNED = [self::UNGUARDED];

    /**
     * What queued work says, in the order the coder is told.
     */
    public const PARTS = ['tries', 'backoff', 'failed'];

    /**
     * The interfaces Laravel queues a class by.
     */
    protected const QUEUED = [
        'Illuminate\Contracts\Queue\ShouldQueue',
        'Illuminate\Contracts\Queue\ShouldQueueAfterCommit',
    ];

    /**
     * Where each part may be said: a property, a method or an attribute.
     * retryUntil() stands in for a number of tries.
     */
    protected const SAID = [
        'tries' => ['properties' => ['tries'], 'methods' => ['tries', 'retryUntil'], 'attributes' => ['Illuminate\Queue\Attributes\Tries']],
        'backoff' => ['properties' => ['backoff'], 'methods' => ['backoff'], 'attributes' => ['Illuminate\Queue\Attributes\Backoff']],
        'failed' => ['properties' => [], 'methods' => ['failed'], 'attributes' => []],
    ];

    /**
     * Find the queued classes in one PHP file and what each leaves out.
     * Empty when the file cannot be read as PHP.
     *
     * @return list<array{class: string, kind: string, at: string, missing: list<string>}>
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
            $interfaces = array_map(fn ($name) => $name->toString(), $class->implements);

            if (array_intersect($interfaces, self::QUEUED) === [] || $class->isAbstract()) {
                continue;
            }

            $properties = array_merge(...array_map(fn ($property) => array_map(fn ($prop) => $prop->name->toString(), $property->props), $class->getProperties()));
            $methods = array_map(fn ($method) => $method->name->toString(), $class->getMethods());
            $attributes = array_merge(...array_map(fn ($group) => array_map(fn ($attribute) => $attribute->name->toString(), $group->attrs), $class->attrGroups));

            $missing = array_values(array_filter(self::PARTS, fn (string $part) => array_intersect($properties, self::SAID[$part]['properties']) === []
                && array_intersect($methods, self::SAID[$part]['methods']) === []
                && array_intersect($attributes, self::SAID[$part]['attributes']) === []));

            $found[] = [
                'class' => $class->namespacedName?->toString() ?? (string) $class->name,
                'kind' => self::kind($path, $class->extends?->toString()),
                'at' => "{$path}:{$class->getStartLine()}",
                'missing' => $missing,
            ];
        }

        return $found;
    }

    /**
     * Find the queued work a patch adds, given a reader of each file as the
     * change leaves it: each queued class in a file it adds, or one it
     * changes that was not queued before. Work the app already queued is
     * not the change's to fix.
     *
     * @param  callable(string): ?string  $contents
     * @return list<array{class: string, kind: string, at: string, missing: list<string>}>
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

            // A changed file whose earlier text cannot be rebuilt is not
            // guessed at.
            if (! $added && $old === null) {
                continue;
            }

            $before = $old === null ? [] : array_column(self::read($file['path'], $old), 'class');

            foreach (self::read($file['path'], $code) as $work) {
                if (! in_array($work['class'], $before, true)) {
                    $found[] = $work;
                }
            }
        }

        return $found;
    }

    /**
     * Get the findings: each queued class that leaves a part out, less
     * those the owner said they want.
     *
     * @param  list<array{class: string, missing: list<string>}>|null  $queued
     * @param  list<string>  $accepted  Identities the owner said they want
     * @return list<array{kind: string, subject: string}>
     */
    public static function findings(?array $queued, array $accepted = []): array
    {
        $findings = array_map(fn (array $work) => ['kind' => self::UNGUARDED, 'subject' => $work['class']], array_filter($queued ?? [], fn (array $work) => $work['missing'] !== []));

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
     * Say what a finding is, for the agent that sends the change back.
     *
     * @param  array{kind: string, subject: string}  $finding
     * @param  list<array{class: string, at: string, missing: list<string>}>  $queued
     */
    public static function finding(array $finding, array $queued): string
    {
        $work = collect($queued)->firstWhere('class', $finding['subject']);
        $says = [
            'tries' => __('how many times to try (a $tries property)'),
            'backoff' => __('how long to wait between tries (a $backoff property)'),
            'failed' => __('what to do when it gives up (a failed() method)'),
        ];

        return __(':class (:at) is queued but does not say :missing. Add them, as Laravel\'s queue documentation shows. If it must run only once, ask the owner to keep it.', [
            'class' => $finding['subject'],
            'at' => $work['at'] ?? '',
            'missing' => implode(__(' or '), array_map(fn (string $part) => $says[$part], $work['missing'] ?? self::PARTS)),
        ]);
    }

    /**
     * Name queued work for the owner: "send invoice reminder" for
     * App\Jobs\SendInvoiceReminder.
     */
    public static function name(string $class): string
    {
        return Str::lower(Str::headline(class_basename($class)));
    }

    /**
     * Name the kind of work Laravel queues: an email, a notification, a
     * listener or a job.
     */
    protected static function kind(string $path, ?string $parent): string
    {
        return match (true) {
            $parent === 'Illuminate\Mail\Mailable' => 'mail',
            $parent === 'Illuminate\Notifications\Notification' => 'notification',
            str_contains($path, '/Listeners/') => 'listener',
            default => 'job',
        };
    }
}
