<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class FillPreviewWithLots
{
    /**
     * Add many records of each kind the app keeps, with the app's own
     * factories, as its tests would make them. The people already there
     * are reused wherever a record belongs to someone, so each of them
     * sees a full app; parents of other kinds are made by the factories.
     * A kind whose factory breaks is left as it was and named.
     *
     * $1 is how many of each kind to add.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $guard = config('auth.defaults.guard');
        $people = config('auth.providers.'.config("auth.guards.{$guard}.provider").'.model');
        $kinds = [];
        foreach (Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
            $class = app()->getNamespace().str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (class_exists($class) && is_subclass_of($class, Illuminate\Database\Eloquent\Model::class) && ! (new ReflectionClass($class))->isAbstract() && method_exists($class, 'factory')) {
                $kinds[] = $class;
            }
        }
        // People first, so the other kinds can belong to them.
        usort($kinds, fn ($a, $b) => (int) ($b === $people) - (int) ($a === $people));
        $made = [];
        $broke = [];
        foreach ($kinds as $class) {
            $table = (new $class)->getTable();
            try {
                Illuminate\Support\Facades\DB::transaction(function () use ($class, $people, $argv) {
                    $factory = $class::factory()->count($class === $people ? max(1, intdiv((int) $argv[1], 4)) : (int) $argv[1]);
                    if (is_string($people) && class_exists($people) && $class !== $people) {
                        $factory = $factory->recycle($people::query()->get());
                    }
                    $factory->create();
                });
                $made[$table] = $class === $people ? max(1, intdiv((int) $argv[1], 4)) : (int) $argv[1];
            } catch (Throwable) {
                $broke[] = $table;
            }
        }
        echo json_encode(['made' => $made, 'broke' => $broke]);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Fill the app on show with many records of each kind, so the owner
     * sees how its pages cope, and say what was added. Only the copy the
     * owner tries is changed, never the app online.
     *
     * @return array{made: array<string, int>, broke: list<string>}
     *
     * @throws ValidationException when the app does not run or has no factories.
     */
    public function handle(Project $project): array
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);

        $output = $this->runPreviewCommand->handle(
            $preview,
            ['php', '-r', self::SCRIPT, '--', (string) Config::integer('builder.preview.lots', 200)],
            300,
            __('Your app could not be filled with lots of examples. This is our fault. Try again.'),
        );
        ReadPreviewData::forget($preview);

        $answer = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        if (! is_array($answer) || ! is_array($answer['made'] ?? null) || ! is_array($answer['broke'] ?? null)) {
            throw ValidationException::withMessages(['app' => __('Your app could not be filled with lots of examples. This is our fault. Try again.')]);
        }

        if ($answer['made'] === []) {
            throw ValidationException::withMessages(['app' => $answer['broke'] === []
                ? __('Your app has no example data to make more of. Ask me to add some.')
                : __('Your app\'s example data could not be made. Ask me to fix its example data.')]);
        }

        return [
            'made' => array_map(intval(...), array_filter($answer['made'], is_int(...))),
            'broke' => array_values(array_filter($answer['broke'], is_string(...))),
        ];
    }

    /**
     * Say in one line what was added, and what could not be.
     *
     * @param  array{made: array<string, int>, broke: list<string>}  $result
     */
    public static function words(array $result): string
    {
        $made = collect($result['made'])->map(fn (int $count, string $table) => number_format($count).' '.str_replace('_', ' ', $table))->values();
        $words = __('Added :made. Open your pages to see how they cope with lots.', ['made' => $made->join(', ', ' and ')]);

        return $result['broke'] === [] ? $words : $words.' '.__('Your app could not make more :broke.', ['broke' => collect($result['broke'])->map(fn (string $table) => str_replace('_', ' ', $table))->join(', ', ' or ')]);
    }
}
