<?php

namespace App\Features;

/**
 * Run the app's tests once more with Laravel's strict model modes on
 * (architecture §12), without changing the app. A PHPUnit extension turns
 * them on after each test's setUp, with handlers that note each problem
 * instead of throwing, so every test runs as before and all problems are
 * seen, not only the first.
 *
 * - An attribute silently discarded by mass assignment: what a form sends
 *   is never saved.
 * - An attribute read that the model does not have, and its table has no
 *   such column: it reads as empty.
 * - A relation loaded one record at a time. This is about speed, and apps
 *   with automatic eager loading do fine without it, so it is only a note.
 *
 * A problem is the change's when the first place in the app's own code it
 * happened in, or the model's file, is a file the change touched. So one
 * extra run is enough, with no second run on the starting commit.
 *
 * @phpstan-type Violation array{kind: string, model: string, attributes: list<string>, at: string|null, test: string}
 */
class StrictModels
{
    /**
     * The check's name, as its result is kept.
     */
    public const CHECK = 'Strict models';

    /**
     * A form's value silently not saved.
     */
    public const DISCARDED = 'discarded';

    /**
     * An attribute the model does not have, read as empty.
     */
    public const MISSING = 'missing';

    /**
     * A relation loaded one record at a time.
     */
    public const LAZY = 'lazy';

    /**
     * Where the bootstrap and the report go. Workspaces never get this
     * folder from the app, and git apply skips it, so neither can reach a
     * change, a commit or the owner's repository.
     */
    public const DIRECTORY = '.builder/strict';

    /**
     * The name of the environment variable that says where to write.
     */
    public const VARIABLE = 'STRICT_MODELS_REPORT';

    /**
     * Problems kept per kind, so a loop cannot fill the report.
     */
    protected const LIMIT = 200;

    /**
     * The file run before the tests: the app's autoloader, and the
     * extension that turns the strict modes on after each test's setUp,
     * once the app has booted and its own settings are in place.
     */
    public static function bootstrap(): string
    {
        $variable = var_export(self::VARIABLE, true);
        $limit = self::LIMIT;

        return <<<PHP
<?php

require getcwd().'/vendor/autoload.php';

final class StrictModelsExtension implements PHPUnit\\Runner\\Extension\\Extension
{
    public function bootstrap(PHPUnit\\TextUI\\Configuration\\Configuration \$configuration, PHPUnit\\Runner\\Extension\\Facade \$facade, PHPUnit\\Runner\\Extension\\ParameterCollection \$parameters): void
    {
        \$report = getenv({$variable});

        if (! is_string(\$report) || \$report === '') {
            return;
        }

        \$facade->registerSubscriber(new class(\$report) implements PHPUnit\\Event\\Test\\PreparedSubscriber
        {
            /** @var array<string, int> */
            private array \$counts = [];

            /** @var array<string, list<string>> */
            private array \$columns = [];

            public function __construct(private string \$report) {}

            public function notify(PHPUnit\\Event\\Test\\Prepared \$event): void
            {
                \$test = \$event->test()->id();
                \$model = Illuminate\\Database\\Eloquent\\Model::class;
                \$auto = method_exists(\$model, 'isAutomaticallyEagerLoadingRelationships') && \$model::isAutomaticallyEagerLoadingRelationships();

                \$model::preventSilentlyDiscardingAttributes();
                \$model::preventAccessingMissingAttributes();
                \$model::preventLazyLoading();
                \$model::handleDiscardedAttributeViolationUsing(fn (\$record, array \$keys) => \$this->note('discarded', \$record, array_values(\$keys), \$test));
                \$model::handleMissingAttributeViolationUsing(function (\$record, string \$key) use (\$test) {
                    // A column the query did not load is not one the model
                    // lacks: only a name the table does not have is noted.
                    \$table = \$record->getConnection()->getName().'.'.\$record->getTable();
                    \$this->columns[\$table] ??= \$record->getConnection()->getSchemaBuilder()->getColumnListing(\$record->getTable());

                    if (! in_array(\$key, \$this->columns[\$table], true)) {
                        \$this->note('missing', \$record, [\$key], \$test);
                    }

                    return null;
                });
                \$model::handleLazyLoadingViolationUsing(function (\$record, string \$relation) use (\$test, \$auto) {
                    if (! \$auto) {
                        \$this->note('lazy', \$record, [\$relation], \$test);
                    }
                });
            }

            private function note(string \$kind, object \$record, array \$attributes, string \$test): void
            {
                if ((\$this->counts[\$kind] = (\$this->counts[\$kind] ?? 0) + 1) > {$limit}) {
                    return;
                }

                \$at = null;
                \$root = getcwd().'/';

                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as \$frame) {
                    \$file = str_replace(\$root, '', \$frame['file'] ?? '');

                    if (\$file !== '' && ! str_starts_with(\$file, '/') && ! str_starts_with(\$file, 'vendor/') && ! str_starts_with(\$file, 'tests/') && ! str_starts_with(\$file, '.builder/')) {
                        \$at = \$file.':'.(\$frame['line'] ?? 0);

                        break;
                    }
                }

                file_put_contents(\$this->report, json_encode(['kind' => \$kind, 'model' => get_class(\$record), 'attributes' => \$attributes, 'at' => \$at, 'test' => \$test]).PHP_EOL, FILE_APPEND);
            }
        });
    }
}

PHP;
    }

    /**
     * Build the check's shell script: write the bootstrap, run the tests
     * given after it with the extension, and remove the folder however it
     * ends. The tests' own outcome is not the check's, so it always ends
     * well unless the folder could not be made.
     */
    public static function script(): string
    {
        $directory = self::DIRECTORY;
        $variable = self::VARIABLE;

        return implode("\n", [
            "d='{$directory}'",
            'rm -rf "$d" && mkdir -p "$d" || exit 1',
            "trap 'rm -rf \"\$d\"; rmdir \"\$(dirname \"\$d\")\" 2>/dev/null' EXIT",
            "trap 'exit 143' INT TERM HUP",
            'printf %s '.escapeshellarg(self::bootstrap()).' > "$d/bootstrap.php"',
            "{$variable}=\"\$d/report.jsonl\" php artisan test --bootstrap=\"\$d/bootstrap.php\" --extension=StrictModelsExtension \"\$@\" > /dev/null 2>&1",
            'cat "$d/report.jsonl" 2>/dev/null',
            'exit 0',
        ]);
    }

    /**
     * Read the problems the run noted. Only class and attribute names are
     * kept, so nothing the app printed reaches the agent as instructions.
     *
     * @return list<Violation>
     */
    public static function parse(string $output): array
    {
        $violations = [];
        $seen = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $data = json_decode($line, true);

            if (! is_array($data) || ! in_array($data['kind'] ?? null, [self::DISCARDED, self::MISSING, self::LAZY], true) || ! is_string($data['model'] ?? null) || preg_match('/^[\w\\\\]+$/', $data['model']) !== 1) {
                continue;
            }

            $attributes = array_values(array_filter(is_array($data['attributes'] ?? null) ? $data['attributes'] : [], fn (mixed $name) => is_string($name) && preg_match('/^\w+$/', $name) === 1));
            $at = is_string($data['at'] ?? null) && preg_match('/^[\w.\/-]+:\d+$/', $data['at']) === 1 ? $data['at'] : null;
            $key = "{$data['kind']}|{$data['model']}|".implode(',', $attributes)."|{$at}";

            if ($attributes === [] || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $violations[] = ['kind' => $data['kind'], 'model' => $data['model'], 'attributes' => $attributes, 'at' => $at, 'test' => is_string($data['test'] ?? null) ? $data['test'] : ''];
        }

        return $violations;
    }

    /**
     * Keep the problems the change made: those that happened in a file it
     * touched, or on a model whose file it touched.
     *
     * @param  list<Violation>  $violations
     * @param  list<string>  $touched  Paths the change and its ancestors touched
     * @return list<Violation>
     */
    public static function theChanges(array $violations, array $touched): array
    {
        return array_values(array_filter($violations, function (array $violation) use ($touched) {
            $file = $violation['at'] === null ? null : substr($violation['at'], 0, (int) strrpos($violation['at'], ':'));
            $model = 'app/'.str_replace('\\', '/', substr($violation['model'], strlen('App\\'))).'.php';

            return in_array($file, $touched, true) || (str_starts_with($violation['model'], 'App\\') && in_array($model, $touched, true));
        }));
    }

    /**
     * Determine if a problem may stop the change: something not saved, or
     * read as empty. Loading one record at a time never does.
     *
     * @param  Violation  $violation
     */
    public static function blocks(array $violation): bool
    {
        return $violation['kind'] !== self::LAZY;
    }

    /**
     * Say what the run found, for the agent that repairs the change.
     *
     * @param  list<Violation>  $violations  The change's
     */
    public static function describe(array $violations): string
    {
        $lines = [];

        foreach ($violations as $violation) {
            $model = class_basename($violation['model']);
            $names = implode(', ', $violation['attributes']);
            $at = $violation['at'] === null ? '' : " at {$violation['at']}";

            $lines[] = match ($violation['kind']) {
                self::DISCARDED => "{$model} silently discarded [{$names}]{$at}: mass assignment drops it, so it is never saved. Add it to the model's fillable attributes if it should be saved, or stop sending it.",
                self::MISSING => "{$model} has no attribute [{$names}]{$at}, so reading it gives null. Use the attribute the model has, or select it in the query.",
                default => "Note, not a failure: {$model}'s {$names} is loaded one record at a time{$at}. Eager load it with with() if a page lists many.",
            };
        }

        return $lines === [] ? 'Ran the tests with strict models on; the change saves and reads its models as written.' : implode("\n", $lines);
    }
}
