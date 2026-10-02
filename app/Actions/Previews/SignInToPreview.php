<?php

namespace App\Actions\Previews;

use App\Models\Preview;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class SignInToPreview
{
    /**
     * Sign one person in through the app itself, as its own sign-in would:
     * its default guard starts a session in its own session store. What
     * comes back is the app's session cookie, sealed with the app's key the
     * way its middleware seals cookies, for the preview host to set.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $guard = auth()->guard();
        if (! $guard instanceof Illuminate\Auth\SessionGuard) { exit(3); }
        $person = $guard->getProvider()->retrieveById($argv[1]);
        if ($person === null) { exit(4); }
        $session = $guard->getSession();
        $session->start();
        $guard->login($person);
        $session->save();
        $name = config('session.cookie');
        $value = encrypt(Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
        echo json_encode(['name' => $name, 'value' => $value, 'minutes' => (int) config('session.lifetime')]);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand, private GrantPreviewAccess $grantPreviewAccess) {}

    /**
     * Sign the app on show in as one of its people, and get the address
     * that opens it that way, on the page given.
     *
     * @throws ValidationException when the app does not run or cannot sign them in.
     */
    public function handle(Project $project, string $person, ?string $path = null): string
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['person' => __('Your app is not running. Start it and try again.')]);

        return $this->grantPreviewAccess->handle($preview, $path, $this->cookie($preview, $person));
    }

    /**
     * Sign one person in to a running preview and get the app's session
     * cookie that keeps them signed in.
     *
     * @return array{name: string, value: string, minutes: int}
     *
     * @throws ValidationException when the app cannot sign them in.
     */
    public function cookie(Preview $preview, string $person): array
    {
        $output = $this->runPreviewCommand->handle(
            $preview,
            ['php', '-r', self::SCRIPT, '--', $person],
            60,
            __('Your app could not sign them in. It may not keep who is signed in the usual Laravel way. This is our fault if it does.'),
        );
        $cookie = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        if (! is_array($cookie) || ! is_string($cookie['name'] ?? null) || ! is_string($cookie['value'] ?? null)) {
            throw ValidationException::withMessages(['person' => __('Your app could not sign them in. This is our fault. Try again.')]);
        }

        return [
            'name' => $cookie['name'],
            'value' => $cookie['value'],
            'minutes' => max(1, (int) ($cookie['minutes'] ?? 120)),
        ];
    }
}
