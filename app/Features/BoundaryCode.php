<?php

namespace App\Features;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * The boundary rules (AppBoundaries) read from the code instead of seen
 * running: a change's new lines that save, queue, send or call out inside
 * a method Laravel runs while it checks who may act, checks the input or
 * builds the answer, and any query or outside call while the app starts.
 *
 * The recorder only sees what the tests run, and never sees the app start,
 * so this covers what it cannot. It reads one method at a time and does not
 * follow calls into other methods, so it finds less than the recorder and
 * can be wrong about intent: each finding is likely, not proven. The
 * methods come from what Laravel itself calls on each kind of class, not
 * from where the app keeps them.
 */
class BoundaryCode
{
    /**
     * The phase of each method Laravel calls, by the kind of class. "*"
     * is every public method.
     *
     * @var array<string, array<string, string>>
     */
    protected const METHODS = [
        'policy' => ['*' => 'authorization'],
        'request' => [
            'authorize' => 'authorization',
            'rules' => 'validation',
            'prepareForValidation' => 'validation',
            'passedValidation' => 'validation',
            'withValidator' => 'validation',
            'after' => 'validation',
            'messages' => 'validation',
            'attributes' => 'validation',
        ],
        'resource' => ['toArray' => 'rendering', 'with' => 'rendering', 'withResponse' => 'rendering', 'paginationInformation' => 'rendering'],
        'provider' => ['boot' => 'boot', 'register' => 'boot'],
    ];

    /**
     * The classes each kind extends, by full name.
     *
     * @var array<string, list<string>>
     */
    protected const PARENTS = [
        'request' => ['Illuminate\Foundation\Http\FormRequest'],
        'resource' => ['Illuminate\Http\Resources\Json\JsonResource', 'Illuminate\Http\Resources\Json\ResourceCollection'],
        'provider' => ['Illuminate\Support\ServiceProvider', 'Illuminate\Foundation\Support\Providers\AuthServiceProvider', 'Illuminate\Foundation\Support\Providers\EventServiceProvider', 'Illuminate\Foundation\Support\Providers\RouteServiceProvider'],
    ];

    /**
     * Methods that save on whatever they are called on.
     */
    protected const WRITES = ['save', 'saveQuietly', 'create', 'createQuietly', 'forceCreate', 'update', 'updateQuietly', 'delete', 'deleteQuietly', 'forceDelete', 'increment', 'decrement', 'insert', 'insertOrIgnore', 'upsert', 'updateOrCreate', 'firstOrCreate', 'updateOrInsert', 'touch', 'attach', 'detach', 'sync', 'syncWithoutDetaching', 'destroy'];

    /**
     * Methods that read saved data, which only the app's start may not do.
     */
    protected const READS = ['all', 'get', 'first', 'firstOrFail', 'find', 'findOrFail', 'where', 'count', 'pluck', 'exists', 'query', 'select', 'table', 'value', 'sum', 'max', 'min'];

    /**
     * Facades whose every call sends or calls out of the app.
     */
    protected const SENDERS = ['Mail' => 'mail', 'Notification' => 'notification', 'Http' => 'http', 'Bus' => 'job', 'Queue' => 'job'];

    /**
     * Functions that queue or send.
     */
    protected const SENDING = ['dispatch' => 'job', 'dispatch_sync' => 'job', 'event' => 'event', 'broadcast' => 'event'];

    /**
     * The finding for each phase.
     */
    public const KINDS = [
        'authorization' => AppBoundaries::CHANGED_WHILE_AUTHORIZING,
        'validation' => AppBoundaries::CHANGED_WHILE_VALIDATING,
        'rendering' => AppBoundaries::CHANGED_WHILE_RENDERING,
        'boot' => AppBoundaries::CHANGED_WHILE_BOOTING,
    ];

    /**
     * Find the calls on the given lines of one PHP file that break a
     * boundary rule. Empty when the file cannot be read as PHP.
     *
     * @param  list<int>  $lines  The lines the change added
     * @return list<array{kind: string, what: string, at: string, in: string}>
     */
    public static function read(string $path, string $code, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        $traverser = new NodeTraverser(new NameResolver);
        $ast = $traverser->traverse($ast);
        $finder = new NodeFinder;
        $found = [];

        foreach ($finder->findInstanceOf($ast, Class_::class) as $class) {
            $kind = self::kind($path, $class);

            if ($kind === null) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                $phase = self::phase($kind, $method);

                if ($phase === null) {
                    continue;
                }

                foreach (self::calls($method, $phase) as $call) {
                    $line = $call->getStartLine();
                    $what = self::effect($call, $phase);

                    if ($what === null || ! in_array($line, $lines, true)) {
                        continue;
                    }

                    $in = ($class->namespacedName?->toString() ?? (string) $class->name).'::'.$method->name->toString();
                    $found["{$line}|{$what}"] ??= ['kind' => self::KINDS[$phase], 'what' => $what, 'at' => "{$path}:{$line}", 'in' => $in];
                }
            }
        }

        ksort($found, SORT_NATURAL);

        return array_values($found);
    }

    /**
     * Find the boundary findings in every PHP file a patch changes, given a
     * reader of each file as the change leaves it: those on the lines the
     * change added ("read"), and every one the file had before the change
     * ("before"), so a finding that only moved is not the change's.
     *
     * @param  callable(string): ?string  $contents
     * @return array{read: list<array{kind: string, what: string, at: string, in: string}>, before: list<array{kind: string, what: string, at: string, in: string}>}
     */
    public static function inPatch(?string $patch, callable $contents): array
    {
        $read = [];
        $before = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (! str_ends_with($file['path'], '.php') || str_starts_with($file['path'], 'tests/') || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            $lines = array_column(PatchSummary::addedLines($file['diff']), 'line');
            $code = $lines === [] ? null : $contents($file['path']);

            if (! is_string($code)) {
                continue;
            }

            array_push($read, ...self::read($file['path'], $code, $lines));
            $old = PatchSummary::before($code, $file['diff']);

            if ($old !== null) {
                array_push($before, ...self::read($file['path'], $old, range(1, substr_count($old, "\n") + 1)));
            }
        }

        return ['read' => $read, 'before' => $before];
    }

    /**
     * Name a finding by what it is, not where: the rule, the method and
     * what it does. A finding the recorder saw is named by the nearest
     * method of the app on the way to it.
     *
     * @param  array{kind: string, what: string, in: string|null}  $finding
     */
    public static function identity(array $finding): string
    {
        $what = strtok($finding['what'], ' ');
        $what = in_array($what, ['insert', 'update', 'delete', 'replace'], true) ? 'save' : $what;

        return implode('|', [$finding['kind'], $finding['in'] ?? '', $what]);
    }

    /**
     * Name the kind of class Laravel treats it as: a policy, a form
     * request, an API resource or a service provider.
     */
    protected static function kind(string $path, Class_ $class): ?string
    {
        $parent = $class->extends?->toString();

        foreach (self::PARENTS as $kind => $parents) {
            if ($parent !== null && in_array($parent, $parents, true)) {
                return $kind;
            }
        }

        // Laravel finds policies by name and folder, and they extend nothing.
        if (str_ends_with((string) $class->name, 'Policy') && str_contains($path, '/Policies/')) {
            return 'policy';
        }

        return null;
    }

    /**
     * Get the phase Laravel runs a method of the given kind of class in.
     */
    protected static function phase(string $kind, ClassMethod $method): ?string
    {
        $name = $method->name->toString();
        $methods = self::METHODS[$kind];

        if (isset($methods[$name])) {
            return $methods[$name];
        }

        return isset($methods['*']) && $method->isPublic() && ! str_starts_with($name, '__') ? $methods['*'] : null;
    }

    /**
     * Get the calls a method makes when Laravel runs it. While the app
     * starts, a closure is only registered, and it runs later.
     *
     * @return list<FuncCall|MethodCall|StaticCall>
     */
    protected static function calls(ClassMethod $method, string $phase): array
    {
        $calls = [];
        $skip = [];

        $finder = new NodeFinder;

        if ($phase === 'boot') {
            foreach ($finder->find($method->stmts ?? [], fn (Node $node) => $node instanceof Closure || $node instanceof ArrowFunction) as $closure) {
                foreach ($finder->find([$closure], fn (Node $node) => true) as $inner) {
                    $skip[spl_object_id($inner)] = true;
                }
            }
        }

        foreach ($finder->find($method->stmts ?? [], fn (Node $node) => $node instanceof FuncCall || $node instanceof MethodCall || $node instanceof StaticCall) as $call) {
            if (! isset($skip[spl_object_id($call)]) && ($call instanceof FuncCall || $call instanceof MethodCall || $call instanceof StaticCall)) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Say what a call does to the world, such as "mail" or "save", or null
     * when it changes nothing the phase forbids.
     */
    protected static function effect(FuncCall|MethodCall|StaticCall $call, string $phase): ?string
    {
        if ($call instanceof FuncCall) {
            $name = $call->name instanceof Name ? $call->name->getLast() : null;

            return self::SENDING[$name ?? ''] ?? null;
        }

        $method = $call->name instanceof Identifier ? $call->name->toString() : null;

        if ($method === null) {
            return null;
        }

        if ($call instanceof StaticCall && $call->class instanceof Name) {
            $class = $call->class->getLast();

            if (isset(self::SENDERS[$class])) {
                return self::SENDERS[$class];
            }

            // Facades of the framework itself that only register things.
            if (in_array($class, ['Gate', 'Route', 'View', 'Event', 'Blade', 'Vite', 'Schema', 'Validator', 'Password', 'Model', 'Date', 'URL', 'Config', 'App', 'Lang', 'Log', 'Context', 'RateLimiter', 'Str', 'Arr', 'Carbon', 'Cache', 'Storage', 'Auth', 'Session', 'Cookie', 'Request', 'Redirect', 'Inertia', 'Response', 'Paginator', 'JsonResource', 'Relation', 'Collection'], true)) {
                return null;
            }
        }

        if ($call instanceof MethodCall && in_array($method, ['notify', 'notifyNow'], true)) {
            return 'notification';
        }

        if (in_array($method, self::WRITES, true)) {
            return 'save';
        }

        // Only the app's start may not read: anything else may look things up.
        if ($phase === 'boot' && $call instanceof StaticCall && in_array($method, self::READS, true)) {
            return 'query';
        }

        return null;
    }
}
