<?php

namespace App\Actions\Previews;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Project;
use App\Workspaces\RunnerDoor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class SendPreviewTwice
{
    /**
     * Send the last form the app took twice, at the same moment: both as
     * the person who sent it, the way a double click would, or the second
     * as someone else, the way two people at once would. The app's own key seals its session cookie, as its middleware
     * does. Both answers come back, and each send's trace as the recorder
     * wrote it.
     *
     * $1 is the recorder's folder, $2 the address the app's server listens on,
     * $3 "one" for one person or "two" for two.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $send = json_decode((string) @file_get_contents($argv[1].'/last-send.json'), true);
        if (! is_array($send)) { exit(4); }
        if (! function_exists('curl_multi_init')) { exit(5); }
        $name = config('session.cookie');
        $seal = fn (string $id) => rawurlencode(encrypt(Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false));
        $senders = [[$send['session'], $send['token']], [$send['session'], $send['token']]];
        $other = null;
        if ($argv[3] === 'two') {
            // The second sender is someone else in the app, signed in the way
            // its own sign-in would, or a second visitor when the first was one.
            $guard = auth()->guard();
            if (! $guard instanceof Illuminate\Auth\SessionGuard) { exit(3); }
            $kept = $guard->getSession();
            $kept->setId($send['session']);
            $kept->start();
            $first = $kept->get($guard->getName());
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            auth()->forgetGuards();
            $guard = auth()->guard();
            $session = $guard->getSession();
            $session->start();
            if ($first !== null) {
                $provider = $guard->getProvider();
                $model = $provider instanceof Illuminate\Auth\EloquentUserProvider ? $provider->createModel() : null;
                $person = $model?->newQuery()->whereKeyNot($first)->oldest($model->getKeyName())->first();
                if ($person === null && $model !== null && method_exists($model, 'factory')) { $person = $model::factory()->create(); }
                if ($person === null) { exit(6); }
                $guard->login($person);
                $other = isset($person->name) && is_string($person->name) ? $person->name : (isset($person->email) && is_string($person->email) ? $person->email : '');
            }
            $session->save();
            $senders[1] = [$session->getId(), $session->token()];
        }
        $trace = $argv[1].'/trace.jsonl';
        clearstatcache();
        $from = is_file($trace) ? filesize($trace) : 0;
        $multi = curl_multi_init();
        $handles = [];
        foreach ([0, 1] as $i) {
            [$id, $token] = $senders[$i];
            $handles[$i] = curl_init('http://'.$argv[2].$send['path']);
            curl_setopt_array($handles[$i], [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query([...$send['input'], '_token' => $token, '_method' => $send['method']]),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Cookie: '.$name.'='.$seal($id), 'Accept: text/html', 'Content-Type: application/x-www-form-urlencoded']]);
            curl_multi_add_handle($multi, $handles[$i]);
        }
        do { $status = curl_multi_exec($multi, $running); if ($running) { curl_multi_select($multi); } } while ($running && $status === CURLM_OK);
        // A send the app answers by asking the person to sign in was turned
        // away, though the answer is a redirect.
        $login = Illuminate\Support\Facades\Route::has('login') ? route('login', [], false) : null;
        $statuses = array_map(fn ($handle) => ($code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE)) >= 300 && $code < 400 && $login !== null
            && parse_url((string) curl_getinfo($handle, CURLINFO_REDIRECT_URL), PHP_URL_PATH) === $login ? 401 : $code, $handles);
        // The recorder writes each trace once its answer has gone.
        $sends = [];
        for ($wait = 0; $wait < 40 && count($sends) < 2; $wait++) {
            usleep(50000);
            clearstatcache();
            $lines = is_file($trace) && filesize($trace) > $from ? explode("\n", (string) file_get_contents($trace, false, null, $from)) : [];
            $sends = array_values(array_filter(array_map(fn ($line) => json_decode($line, true), $lines), fn ($op) => is_array($op) && ($op['route'] ?? null) === $send['route']));
        }
        echo json_encode(['statuses' => $statuses, 'sends' => array_slice($sends, 0, 2), 'other' => $other], JSON_INVALID_UTF8_SUBSTITUTE);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunWorkspaceCommand $runWorkspaceCommand, private ReadPreviewHappenings $readPreviewHappenings) {}

    /**
     * Send the last form twice at once, by one person or two, and say what
     * came of it. other names the second person, or is null when there was
     * none or they were a signed-out visitor.
     *
     * @return array{words: string, broke: bool, other: string|null, sends: list<array{id: string, page: string, status: int, outcome: string|null, slow: string|null, did: list<array{text: string, failed: bool}>, times: int}>}
     *
     * @throws ValidationException when the app does not run or has taken no form yet.
     */
    public function handle(Project $project, bool $twoPeople = false): array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview?->workspace === null) {
            throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);
        }

        $listen = Config::get('builder.preview.listen_host') ?? RunnerDoor::listenHost((string) $preview->upstream_url);
        $host = in_array($listen, ['0.0.0.0', '::', '[::]'], true) ? '127.0.0.1' : $listen;
        $directory = trim(Config::string('builder.preview.recorder.directory'), '/');

        $result = $this->runWorkspaceCommand->handle($preview->workspace, ['php', '-r', self::SCRIPT, '--', $directory, "{$host}:{$preview->port}", $twoPeople ? 'two' : 'one'], 120, $preview->environment());
        Cache::forget("previews:{$preview->id}:trace");

        if ($result->exit_code === 4) {
            throw ValidationException::withMessages(['app' => __('Send a form in your app first, such as one that adds something. Then try this again.')]);
        }

        if ($result->exit_code === 3) {
            throw ValidationException::withMessages(['app' => __('Your app does not keep who is signed in the usual Laravel way, so we cannot send this as someone else.')]);
        }

        if ($result->exit_code === 6) {
            throw ValidationException::withMessages(['app' => __('Your app has only one person and no way to make example people. Sign up a second person in your app, then try this again.')]);
        }

        $answer = json_decode((string) collect(explode("\n", (string) $result->output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        if ($result->exit_code !== 0 || $result->timed_out || ! is_array($answer) || ! is_array($answer['statuses'] ?? null) || count($answer['statuses']) !== 2 || ! is_array($answer['sends'] ?? null)) {
            throw ValidationException::withMessages(['app' => __('Your app could not be sent the form twice. This is our fault. Try again.')]);
        }

        $statuses = array_values(array_map(intval(...), $answer['statuses']));
        $sends = [];

        foreach (array_filter($answer['sends'], is_array(...)) as $send) {
            $sends[] = $this->readPreviewHappenings->describe($send, count($sends) + 1);
        }

        return [
            'words' => $this->words($statuses, $sends),
            'broke' => max(0, ...$statuses) >= 500 || in_array(0, $statuses, true),
            'other' => is_string($answer['other'] ?? null) && $answer['other'] !== '' ? $answer['other'] : null,
            'sends' => $sends,
        ];
    }

    /**
     * Say in one line what sending twice at once did.
     *
     * @param  list<int>  $statuses
     * @param  list<array{did: list<array{text: string, failed: bool}>}>  $sends
     */
    protected function words(array $statuses, array $sends): string
    {
        $through = count(array_filter($statuses, fn (int $status) => $status > 0 && $status < 400));
        $saved = array_values(array_filter(array_map(fn (array $send) => collect($send['did'])->first(fn (array $step) => str_starts_with($step['text'], __('Saved a new')))['text'] ?? null, $sends)));

        return match (true) {
            max(0, ...$statuses) >= 500 || in_array(0, $statuses, true) => __('One of them broke, so that person saw an error page. See Problems for why.'),
            $through === 2 && count($saved) === 2 => __('Both went through, so it was done twice: :did, two times.', ['did' => lcfirst($saved[0])]),
            $through === 2 => __('Both went through, and nothing was saved twice.'),
            $through === 1 => __('One went through, and your app turned the other away.'),
            default => __('Your app turned both away. It may want the form filled in again first.'),
        };
    }
}
