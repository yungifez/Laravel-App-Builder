<?php

namespace App\Actions\Previews;

use App\Models\Preview;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class MakePreviewPerson
{
    /**
     * Make one person through the app itself, with the factory of the model
     * its default guard signs in, as the app's own tests would.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $guard = config('auth.defaults.guard');
        $model = config('auth.providers.'.config("auth.guards.{$guard}.provider").'.model');
        if (! is_string($model) || ! class_exists($model) || ! method_exists($model, 'factory')) { exit(5); }
        $person = $model::factory()->create();
        echo json_encode([
            'id' => (string) $person->getAuthIdentifier(),
            'name' => isset($person->name) && is_string($person->name) ? $person->name : null,
            'email' => isset($person->email) && is_string($person->email) ? $person->email : null,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Make a test person in the app on show, saved with its test data.
     *
     * @return array{id: string, name: string|null, email: string|null}
     *
     * @throws ValidationException when the app does not run or cannot make one.
     */
    public function handle(Project $project): array
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);

        return $this->in($preview);
    }

    /**
     * Make a test person in a running preview.
     *
     * @return array{id: string, name: string|null, email: string|null}
     *
     * @throws ValidationException when the app cannot make one.
     */
    public function in(Preview $preview): array
    {
        $output = $this->runPreviewCommand->handle(
            $preview,
            ['php', '-r', self::SCRIPT],
            60,
            __('Your app has no way to make example people. Sign up in your app instead.'),
        );
        $person = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        if (! is_array($person) || ! is_string($person['id'] ?? null)) {
            throw ValidationException::withMessages(['app' => __('Your app could not make a test person. This is our fault. Try again.')]);
        }

        ReadPreviewPeople::forget($preview);

        return [
            'id' => $person['id'],
            'name' => is_string($person['name'] ?? null) ? $person['name'] : null,
            'email' => is_string($person['email'] ?? null) ? $person['email'] : null,
        ];
    }
}
