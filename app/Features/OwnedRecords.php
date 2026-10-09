<?php

namespace App\Features;

use Illuminate\Support\Str;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Records that belong to someone, read from the change's code (architecture
 * §12). When a change gives a table a column naming its owner, such as
 * user_id or team_id, the table's model must keep each owner's records
 * apart: a policy that reads that owner, or a global scope. Without one,
 * any signed-in person who knows an address can reach another's records.
 */
class OwnedRecords
{
    /**
     * A model whose records name an owner, with nothing that keeps them
     * apart.
     */
    public const UNGUARDED = 'owner_unchecked';

    /**
     * The kinds the owner may say they want, such as records that are
     * public on purpose.
     */
    public const OWNED = [self::UNGUARDED];

    /**
     * Where the app's providers register policies by hand.
     */
    protected const PROVIDERS = ['app/Providers/AppServiceProvider.php', 'app/Providers/AuthServiceProvider.php'];

    /**
     * The column types Laravel makes a key to another table with.
     */
    protected const KEYS = ['foreignId', 'foreignUuid', 'foreignUlid', 'unsignedBigInteger', 'bigInteger', 'unsignedInteger', 'integer', 'uuid', 'ulid'];

    /**
     * Find the columns naming an owner that the change's migrations add,
     * by table.
     *
     * @param  list<string>  $columns  The column names that name an owner
     * @param  callable(string): ?string  $contents
     * @return list<array{table: string, column: string, at: string}>
     */
    public static function columns(?string $patch, array $columns, callable $contents): array
    {
        $found = [];

        foreach (MigrationChecks::added($patch) as $path) {
            $code = $contents($path);
            $ast = is_string($code) ? self::parse($code) : null;

            if ($ast === null) {
                continue;
            }

            foreach ((new NodeFinder)->findInstanceOf($ast, StaticCall::class) as $call) {
                $table = self::string($call->args[0] ?? null);

                if (! self::isCall($call, 'Illuminate\Support\Facades\Schema', ['create', 'table']) || $table === null) {
                    continue;
                }

                foreach ((new NodeFinder)->findInstanceOf($call->args, MethodCall::class) as $method) {
                    $column = self::column($method);

                    if ($column !== null && in_array($column, $columns, true)) {
                        $found["{$table}|{$column}"] ??= ['table' => $table, 'column' => $column, 'at' => "{$path}:{$method->getStartLine()}"];
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * Find each model whose table the change gives an owner column, and
     * what keeps its records apart: a policy that reads the owner, a
     * global scope, or nothing. A table with no model, such as a pivot
     * table, is left out.
     *
     * @param  list<string>  $columns  The column names that name an owner
     * @param  callable(string): ?string  $contents
     * @return list<array{model: string, table: string, column: string, at: string, guard: string|null, policy: string|null}>
     */
    public static function inPatch(?string $patch, array $columns, callable $contents): array
    {
        $found = [];
        $providers = array_map($contents, self::PROVIDERS);

        foreach (self::columns($patch, $columns, $contents) as $owned) {
            $model = self::model($owned['table'], $patch, $contents);

            if ($model === null) {
                continue;
            }

            [$class, $ast] = $model;
            $relation = Str::beforeLast($owned['column'], '_id');
            $policy = null;
            $policyAst = null;

            foreach (self::policies($class, $ast, $providers) as $candidate) {
                $code = $contents(self::path($candidate));
                $policyAst = is_string($code) ? self::parse($code) : null;

                if ($policyAst !== null) {
                    $policy = $candidate;

                    break;
                }
            }

            $guard = match (true) {
                $policyAst !== null && self::reads($policyAst, [$owned['column'], $relation]) => 'policy',
                self::scoped($ast) => 'scope',
                default => null,
            };

            $found[] = [...$owned, 'model' => $class, 'guard' => $guard, 'policy' => $policy];
        }

        return $found;
    }

    /**
     * Get the findings: each model with an owner column and nothing that
     * keeps its records apart, less those the owner said they want.
     *
     * @param  list<array{model: string, guard: string|null}>|null  $owned
     * @param  list<string>  $accepted  Identities the owner said they want
     * @return list<array{kind: string, subject: string}>
     */
    public static function findings(?array $owned, array $accepted = []): array
    {
        $findings = [];

        foreach ($owned ?? [] as $record) {
            if ($record['guard'] === null) {
                $findings[$record['model']] = ['kind' => self::UNGUARDED, 'subject' => $record['model']];
            }
        }

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
     * @param  list<array{model: string, table: string, column: string, at: string, policy: string|null}>  $owned
     */
    public static function finding(array $finding, array $owned): string
    {
        $record = collect($owned)->firstWhere('model', $finding['subject']);
        $column = $record['column'] ?? 'user_id';
        $replace = ['model' => $finding['subject'], 'table' => $record['table'] ?? '', 'column' => $column, 'at' => $record['at'] ?? ''];

        return ($record['policy'] ?? null) === null
            ? __(':table gets :column (:at), so its records belong to someone, but nothing keeps one owner\'s :model records from another. Add a policy that checks :column against the signed-in person, and authorize each route that reads or changes them. If anyone may see them, ask the owner to keep it.', $replace)
            : __(':table gets :column (:at), but :model\'s policy never reads it, so it does not keep one owner\'s records from another. Make the policy check :column against the signed-in person. If anyone may see them, ask the owner to keep it.', $replace);
    }

    /**
     * Name records for the owner: "room bookings" for App\Models\RoomBooking.
     */
    public static function name(string $model): string
    {
        return Str::lower(Str::headline(Str::plural(class_basename($model))));
    }

    /**
     * Get the column a Blueprint call adds when it is a key: the name
     * given, or for foreignIdFor(Team::class), team_id.
     */
    protected static function column(MethodCall $method): ?string
    {
        if (! $method->name instanceof Identifier) {
            return null;
        }

        $name = $method->name->toString();

        if ($name === 'foreignIdFor') {
            $model = $method->args[0] ?? null;

            return $model instanceof Arg && $model->value instanceof ClassConstFetch && $model->value->class instanceof Name
                ? Str::snake(class_basename($model->value->class->toString())).'_id'
                : self::string($method->args[1] ?? null);
        }

        return in_array($name, self::KEYS, true) ? self::string($method->args[0] ?? null) : null;
    }

    /**
     * Find the model for a table: one in the change that names the table,
     * or the one Laravel's naming gives, App\Models\RoomBooking for
     * room_bookings.
     *
     * @param  callable(string): ?string  $contents
     * @return array{0: string, 1: array<Node>}|null
     */
    protected static function model(string $table, ?string $patch, callable $contents): ?array
    {
        $paths = ['app/Models/'.Str::studly(Str::singular($table)).'.php'];

        foreach (PatchSummary::files($patch) as $file) {
            if (str_starts_with($file['path'], 'app/Models/') && str_ends_with($file['path'], '.php')) {
                $paths[] = $file['path'];
            }
        }

        foreach (array_unique($paths) as $path) {
            $code = $contents($path);
            $ast = is_string($code) ? self::parse($code) : null;
            $class = $ast === null ? null : (new NodeFinder)->findFirstInstanceOf($ast, Class_::class);

            if ($class instanceof Class_ && $class->namespacedName !== null && self::table($class) === $table) {
                return [$class->namespacedName->toString(), $ast];
            }
        }

        return null;
    }

    /**
     * Get a model's table: the one it names, or Laravel's plural of its
     * name.
     */
    protected static function table(Class_ $class): string
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === 'Illuminate\Database\Eloquent\Attributes\Table' && ($name = self::string($attribute->args[0] ?? null)) !== null) {
                    return $name;
                }
            }
        }

        foreach ($class->stmts as $statement) {
            if ($statement instanceof Property && $statement->props[0]->name->toString() === 'table' && $statement->props[0]->default instanceof String_) {
                return $statement->props[0]->default->value;
            }
        }

        return Str::snake(Str::pluralStudly((string) $class->name));
    }

    /**
     * Find the policies Laravel may use for a model: one the model names,
     * one a provider registers, or those Laravel's naming gives.
     *
     * @param  array<Node>  $model
     * @param  list<string|null>  $providers
     * @return list<string>
     */
    protected static function policies(string $class, array $model, array $providers): array
    {
        foreach ((new NodeFinder)->findInstanceOf($model, Node\Attribute::class) as $attribute) {
            if ($attribute->name->toString() === 'Illuminate\Database\Eloquent\Attributes\UsePolicy' && ($policy = self::className($attribute->args[0] ?? null)) !== null) {
                return [$policy];
            }
        }

        foreach ($providers as $code) {
            $ast = is_string($code) ? self::parse($code) : null;

            foreach ($ast === null ? [] : (new NodeFinder)->find($ast, fn (Node $node) => $node instanceof StaticCall || $node instanceof ArrayItem) as $node) {
                if ($node instanceof StaticCall && self::isCall($node, 'Illuminate\Support\Facades\Gate', ['policy']) && self::className($node->args[0] ?? null) === $class) {
                    return array_filter([self::className($node->args[1] ?? null)]);
                }

                if ($node instanceof ArrayItem && $node->key instanceof ClassConstFetch && $node->key->class instanceof Name && $node->key->class->toString() === $class && $node->value instanceof ClassConstFetch && $node->value->class instanceof Name) {
                    return [$node->value->class->toString()];
                }
            }
        }

        $namespace = Str::beforeLast($class, '\\');

        return [
            str_replace('\\Models', '\\Policies', $namespace).'\\'.class_basename($class).'Policy',
            str_replace('\\Models', '\\Models\\Policies', $namespace).'\\'.class_basename($class).'Policy',
        ];
    }

    /**
     * Whether a policy reads the owner: the column, or the relation named
     * after it, such as $post->user_id, $post->user or $post->team().
     *
     * @param  array<Node>  $policy
     * @param  list<string>  $names
     */
    protected static function reads(array $policy, array $names): bool
    {
        return (new NodeFinder)->findFirst($policy, fn (Node $node) => (($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch || $node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Identifier && in_array($node->name->toString(), $names, true))
            || ($node instanceof String_ && in_array($node->value, $names, true))) !== null;
    }

    /**
     * Whether a model has a global scope: #[ScopedBy], or one it adds as
     * it boots.
     *
     * @param  array<Node>  $model
     */
    protected static function scoped(array $model): bool
    {
        return (new NodeFinder)->findFirst($model, fn (Node $node) => ($node instanceof Node\Attribute && $node->name->toString() === 'Illuminate\Database\Eloquent\Attributes\ScopedBy')
            || ($node instanceof StaticCall && $node->name instanceof Identifier && $node->name->toString() === 'addGlobalScope')) !== null;
    }

    /**
     * Whether a static call is one of the facade's methods.
     *
     * @param  list<string>  $methods
     */
    protected static function isCall(StaticCall $call, string $facade, array $methods): bool
    {
        return $call->class instanceof Name && in_array($call->class->toString(), [$facade, class_basename($facade)], true)
            && $call->name instanceof Identifier && in_array($call->name->toString(), $methods, true);
    }

    /**
     * Get the class an argument names with ::class.
     */
    protected static function className(mixed $arg): ?string
    {
        return $arg instanceof Arg && $arg->value instanceof ClassConstFetch && $arg->value->class instanceof Name
            ? $arg->value->class->toString()
            : null;
    }

    /**
     * Get the text of a string argument.
     */
    protected static function string(mixed $arg): ?string
    {
        return $arg instanceof Arg && $arg->value instanceof String_ ? $arg->value->value : null;
    }

    /**
     * Get where Laravel's autoloading keeps an App class.
     */
    protected static function path(string $class): string
    {
        return 'app/'.str_replace('\\', '/', Str::after($class, 'App\\')).'.php';
    }

    /**
     * Read PHP code with names resolved, or null when it is not PHP.
     *
     * @return array<Node>|null
     */
    protected static function parse(string $code): ?array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        } catch (Error) {
            return null;
        }

        return $ast === null ? null : (new NodeTraverser(new NameResolver))->traverse($ast);
    }
}
