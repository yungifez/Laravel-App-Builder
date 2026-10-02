<?php

namespace App\Actions\Previews;

use App\Models\Preview;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

class ReadPreviewPeople
{
    /**
     * How many people are offered, the newest first.
     */
    public const LIMIT = 20;

    /**
     * List the people who can sign in to the app, through the app itself:
     * the model its default guard signs in, whatever the app calls it.
     * Only what tells one person from another is read.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $guard = config('auth.defaults.guard');
        $model = config('auth.providers.'.config("auth.guards.{$guard}.provider").'.model');
        if (! is_string($model) || ! class_exists($model)) { exit(3); }
        $people = $model::query()->orderByDesc((new $model)->getKeyName())->limit((int) $argv[1])->get();
        echo json_encode($people->map(fn ($person) => [
            'id' => (string) $person->getAuthIdentifier(),
            'name' => isset($person->name) && is_string($person->name) ? $person->name : null,
            'email' => isset($person->email) && is_string($person->email) ? $person->email : null,
        ])->values(), JSON_INVALID_UTF8_SUBSTITUTE);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Get the people the owner can sign in to the app on show as, or null
     * while it does not run or keeps no one who signs in.
     *
     * @return list<array{id: string, name: string|null, email: string|null}>|null
     */
    public function handle(Project $project): ?array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview === null) {
            return null;
        }

        $output = (string) Cache::remember(self::key($preview), now()->addSeconds(4), fn () => rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', '-r', self::SCRIPT, '--', (string) self::LIMIT], 60, ''),
            '',
            report: false,
        ));
        $people = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '[')), true);

        if (! is_array($people)) {
            return null;
        }

        return array_values(array_filter(array_map(fn ($person) => is_array($person) && is_string($person['id'] ?? null) ? [
            'id' => $person['id'],
            'name' => is_string($person['name'] ?? null) ? $person['name'] : null,
            'email' => is_string($person['email'] ?? null) ? $person['email'] : null,
        ] : null, $people)));
    }

    /**
     * Forget who was read, after someone was added.
     */
    public static function forget(Preview $preview): void
    {
        Cache::forget(self::key($preview));
    }

    protected static function key(Preview $preview): string
    {
        return "previews:{$preview->id}:people";
    }
}
