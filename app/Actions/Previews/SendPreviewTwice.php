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
     * Send the last form the app took twice, at the same moment, as the
     * person who sent it, the way a double click or two people at once
     * would. The app's own key seals its session cookie, as its middleware
     * does. Both answers come back, and each send's trace as the recorder
     * wrote it.
     *
     * $1 is the recorder's folder, $2 the address the app's server listens on.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $send = json_decode((string) @file_get_contents($argv[1].'/last-send.json'), true);
        if (! is_array($send)) { exit(4); }
        if (! function_exists('curl_multi_init')) { exit(5); }
        $name = config('session.cookie');
        $cookie = encrypt(Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$send['session'], false);
        $body = http_build_query([...$send['input'], '_token' => $send['token'], '_method' => $send['method']]);
        $trace = $argv[1].'/trace.jsonl';
        clearstatcache();
        $from = is_file($trace) ? filesize($trace) : 0;
        $multi = curl_multi_init();
        $handles = [];
        foreach ([0, 1] as $i) {
            $handles[$i] = curl_init('http://'.$argv[2].$send['path']);
            curl_setopt_array($handles[$i], [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Cookie: '.$name.'='.rawurlencode($cookie), 'Accept: text/html', 'Content-Type: application/x-www-form-urlencoded']]);
            curl_multi_add_handle($multi, $handles[$i]);
        }
        do { $status = curl_multi_exec($multi, $running); if ($running) { curl_multi_select($multi); } } while ($running && $status === CURLM_OK);
        $statuses = array_map(fn ($handle) => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $handles);
        // The recorder writes each trace once its answer has gone.
        $sends = [];
        for ($wait = 0; $wait < 40 && count($sends) < 2; $wait++) {
            usleep(50000);
            clearstatcache();
            $lines = is_file($trace) && filesize($trace) > $from ? explode("\n", (string) file_get_contents($trace, false, null, $from)) : [];
            $sends = array_values(array_filter(array_map(fn ($line) => json_decode($line, true), $lines), fn ($op) => is_array($op) && ($op['route'] ?? null) === $send['route']));
        }
        echo json_encode(['statuses' => $statuses, 'sends' => array_slice($sends, 0, 2)]);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunWorkspaceCommand $runWorkspaceCommand, private ReadPreviewHappenings $readPreviewHappenings) {}

    /**
     * Send the last form twice at once, and say what came of it.
     *
     * @return array{words: string, broke: bool, sends: list<array{id: string, page: string, status: int, outcome: string|null, did: list<array{text: string, failed: bool}>, times: int}>}
     *
     * @throws ValidationException when the app does not run or has taken no form yet.
     */
    public function handle(Project $project): array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview?->workspace === null) {
            throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);
        }

        $listen = Config::get('builder.preview.listen_host') ?? RunnerDoor::listenHost((string) $preview->upstream_url);
        $host = in_array($listen, ['0.0.0.0', '::', '[::]'], true) ? '127.0.0.1' : $listen;
        $directory = trim(Config::string('builder.preview.recorder.directory'), '/');

        $result = $this->runWorkspaceCommand->handle($preview->workspace, ['php', '-r', self::SCRIPT, '--', $directory, "{$host}:{$preview->port}"], 120, $preview->environment());
        Cache::forget("previews:{$preview->id}:trace");

        if ($result->exit_code === 4) {
            throw ValidationException::withMessages(['app' => __('Send a form in your app first, such as one that adds something. Then try this again.')]);
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

        return ['words' => $this->words($statuses, $sends), 'broke' => max(0, ...$statuses) >= 500 || in_array(0, $statuses, true), 'sends' => $sends];
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
