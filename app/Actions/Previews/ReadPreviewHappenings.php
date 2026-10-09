<?php

namespace App\Actions\Previews;

use App\Features\AppRoutes;
use App\Models\Project;
use App\Previews\ScheduledTasks;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ReadPreviewHappenings
{
    /**
     * How many of the latest things the app did are shown.
     */
    public const LIMIT = 30;

    /**
     * The end of the trace that is read. What came before is not shown.
     */
    protected const TAIL_BYTES = 400_000;

    /**
     * What the owner can make fail, and the words for each.
     *
     * @var array<string, string>
     */
    public const FAULTS = [
        'mail' => 'Email is down',
        'http' => 'Outside services do not answer',
        'file' => 'Storage is full',
        'cache' => 'The cache is down',
        'notification' => 'Notices do not go out',
    ];

    /**
     * Tables the framework keeps for itself. Reading or writing them is
     * not something the owner's app did.
     */
    protected const FRAMEWORK_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens', 'telescope_entries', 'pulse_entries'];

    /**
     * Laravel's health page.
     */
    protected const HEALTH = '/up';

    public function __construct(private ReadPreviewLog $readPreviewLog, private WorkspaceManager $workspaces) {}

    /**
     * Get what the app on show did behind its last pages, newest first, in
     * plain words: what it saved, sent, stored and asked. And which kind of
     * thing the owner has made fail, if any.
     *
     * "again" names the newest form the owner can send twice at once (see
     * SendPreviewTwice), when the recorder kept it.
     *
     * @return array{fault: string, again: string|null, requests: list<array{id: string, page: string, status: int, outcome: string|null, slow: string|null, did: list<array{text: string, failed: bool}>, times: int}>}
     */
    public function handle(Project $project): array
    {
        $preview = $this->readPreviewLog->preview($project);
        $workspace = $preview?->workspace;

        if ($preview === null || $workspace === null || ! Config::boolean('builder.preview.recorder.enabled')) {
            return ['fault' => 'none', 'again' => null, 'requests' => []];
        }

        $directory = rtrim(Config::string('builder.preview.recorder.directory'), '/');
        $driver = $this->workspaces->driver($workspace->driver);
        $id = (string) $workspace->driver_id;

        $trace = (string) Cache::remember("previews:{$preview->id}:trace", now()->addSeconds(3), fn () => rescue(
            fn () => $driver->readFile($id, "{$directory}/trace.jsonl", self::TAIL_BYTES),
            '',
            report: false,
        ));
        $fault = json_decode((string) rescue(fn () => $driver->readFile($id, "{$directory}/fault.json"), '', report: false), true);
        $kept = json_decode((string) rescue(fn () => $driver->readFile($id, "{$directory}/last-send.json"), '', report: false), true);
        $kept = is_array($kept) && is_string($kept['route'] ?? null) ? $kept['route'] : null;
        $again = null;

        $lines = array_values(array_filter(explode("\n", $trace), fn (string $line) => str_starts_with($line, '{')));
        $requests = [];

        foreach (array_reverse(array_slice($lines, -self::LIMIT * 4)) as $at => $line) {
            $operation = json_decode($line, true);

            if (! is_array($operation) || ! is_int($operation['status'] ?? null)) {
                continue;
            }

            // Laravel's health page is opened by our own check that the app
            // has started (see StartPreview), never by the owner.
            if (in_array(strtoupper((string) ($operation['method'] ?? 'GET')), ['GET', 'HEAD'], true) && Str::before((string) ($operation['route'] ?? ''), '#') === self::HEALTH) {
                continue;
            }

            $request = $this->request($operation, count($lines) - $at);
            $last = array_key_last($requests);
            $sent = $kept !== null && ($operation['route'] ?? null) === $kept && ! in_array(strtoupper((string) ($operation['method'] ?? 'GET')), ['GET', 'HEAD'], true);

            // The same page doing the same again is counted, not listed
            // again, so one reload loop does not hide what came before it.
            if ($last !== null && $this->same($requests[$last], $request)) {
                $requests[$last]['times']++;
                $again ??= $sent ? $requests[$last]['id'] : null;

                continue;
            }

            if (count($requests) === self::LIMIT) {
                break;
            }

            $requests[] = $request;
            $again ??= $sent ? $request['id'] : null;
        }

        return [
            'fault' => is_array($fault) && isset(self::FAULTS[$fault['kind'] ?? '']) ? $fault['kind'] : 'none',
            'again' => $again,
            'requests' => $requests,
        ];
    }

    /**
     * Whether two requests read the same to the owner.
     *
     * @param  array{page: string, status: int, outcome: string|null, did: list<array{text: string, failed: bool}>}  $one
     * @param  array{page: string, status: int, outcome: string|null, did: list<array{text: string, failed: bool}>}  $other
     */
    protected function same(array $one, array $other): bool
    {
        return [$one['page'], $one['status'], $one['did']] === [$other['page'], $other['status'], $other['did']];
    }

    /**
     * Say one request the recorder wrote as the owner reads it.
     *
     * @param  array<string, mixed>  $operation
     * @return array{id: string, page: string, status: int, outcome: string|null, slow: string|null, did: list<array{text: string, failed: bool}>, times: int}
     */
    public function describe(array $operation, int $number): array
    {
        return $this->request([...$operation, 'status' => (int) ($operation['status'] ?? 0)], $number);
    }

    /**
     * Say one request as the owner reads it.
     *
     * @param  array<string, mixed>  $operation
     * @return array{id: string, page: string, status: int, outcome: string|null, slow: string|null, did: list<array{text: string, failed: bool}>, times: int}
     */
    protected function request(array $operation, int $number): array
    {
        $method = strtoupper((string) ($operation['method'] ?? 'GET'));
        $route = (string) ($operation['route'] ?? '');
        $status = (int) $operation['status'];
        $did = [];
        $read = [];

        foreach (is_array($operation['effects'] ?? null) ? $operation['effects'] : [] as $effect) {
            if (! is_array($effect)) {
                continue;
            }

            // A save is said on its own; only a read is "looked at".
            if (($effect['kind'] ?? null) === 'query' && preg_match('/^\s*select\b/i', (string) ($effect['sql'] ?? '')) === 1) {
                foreach ($this->tables((string) ($effect['sql'] ?? '')) as $table) {
                    $read[$table] = true;
                }
            }

            $text = $this->words($effect);

            if ($text !== null) {
                $did[] = ['text' => $text, 'failed' => ($effect['failed'] ?? false) === true];
            }
        }

        if ($read !== []) {
            $did[] = ['text' => __('Looked at :tables', ['tables' => implode(', ', array_map(fn (string $table) => str_replace('_', ' ', $table), array_keys($read)))]), 'failed' => false];
        }

        return [
            'id' => "{$number}",
            'page' => $this->page($method, $route),
            'status' => $status,
            'outcome' => $this->outcome($status, $operation),
            'slow' => $this->slow($operation),
            'did' => $did,
            'times' => 1,
        ];
    }

    /**
     * Say that a page was slow, and how often it asked the database when
     * that was a lot: "Slow: took 2.4 seconds, looking things up 340 times".
     *
     * @param  array<string, mixed>  $operation
     */
    protected function slow(array $operation): ?string
    {
        $ms = $operation['ms'] ?? null;

        if (! is_int($ms) || $ms < Config::integer('builder.preview.recorder.slow_ms')) {
            return null;
        }

        $words = __('Slow: took :seconds seconds', ['seconds' => number_format($ms / 1000, 1)]);
        $lookups = $operation['lookups'] ?? null;

        return is_int($lookups) && $lookups >= Config::integer('builder.preview.recorder.many_lookups')
            ? $words.__(', looking things up :count times', ['count' => number_format($lookups)])
            : $words;
    }

    /**
     * Say the page a request was for: "Opened /bookings", "Sent the form on /bookings".
     */
    protected function page(string $method, string $route): string
    {
        // A part of a page that updates on its own is named by that part.
        if (AppRoutes::isPart($route)) {
            return (string) __('Updated :part', ['part' => AppRoutes::address("{$method} {$route}")]);
        }

        $route = Str::before($route, '#');
        $path = $route === '' ? __('a page') : $route;

        return match (true) {
            $method === 'ARTISAN' => (string) __('Ran a task: :name', ['name' => ScheduledTasks::words($route)]),
            $method === 'JOB' => (string) __('Ran in the background: :name', ['name' => $this->name($route)]),
            $method === 'GET', $method === 'HEAD' => (string) __('Opened :path', ['path' => $path]),
            $method === 'DELETE' => (string) __('Deleted from :path', ['path' => $path]),
            default => (string) __('Sent a form on :path', ['path' => $path]),
        };
    }

    /**
     * Say how a request ended, when that is worth a word.
     *
     * @param  array<string, mixed>  $operation
     */
    protected function outcome(int $status, array $operation): ?string
    {
        return match (true) {
            $status >= 500 => (string) __('Ended in an error. See Problems.'),
            $status === 422 => (string) __('Asked the visitor to fix the form'),
            $status === 419 => (string) __('The page had been open too long; asked to try again'),
            $status === 401, $status === 403 => (string) __('Refused: not allowed'),
            $status === 404 => (string) __('Nothing at that address'),
            $status === 429 => (string) __('Asked the visitor to slow down'),
            $status >= 300 && $status < 400 => (string) __('Sent the visitor on to another page'),
            default => null,
        };
    }

    /**
     * Say one thing the app did, or nothing for a thing not worth a line.
     *
     * @param  array<string, mixed>  $effect
     */
    protected function words(array $effect): ?string
    {
        $what = (string) ($effect['what'] ?? '');
        $failed = ($effect['failed'] ?? false) === true;

        return match ($effect['kind'] ?? null) {
            'query' => $this->write((string) ($effect['sql'] ?? '')),
            'mail' => (string) __($failed ? 'Could not send the email: :name' : 'Sent an email: :name', ['name' => $this->name($what, ['Mail', 'Mailable', 'Notification'])]),
            'notification' => (string) __($failed ? 'Could not leave the notice: :name' : 'Left a notice: :name', ['name' => $this->name($what, ['Notification'])]),
            'cache' => (string) __(match ($what) {
                'forget' => $failed ? 'Could not forget what it kept for later' : 'Forgot what it kept for later',
                'read' => $failed ? 'Could not read what it kept for later' : 'Read what it kept for later',
                default => $failed ? 'Could not keep something for later' : 'Kept something for later',
            }),
            'job' => (string) __(($effect['again'] ?? false) ? 'Ran a background task again: :name' : (($effect['later'] ?? false) ? 'Put a task in the background for later: :name' : 'Ran a background task: :name'), ['name' => $this->name($what, ['Job'])]),
            'http' => (string) __($failed ? 'Could not reach :service' : 'Asked :service', ['service' => trim(Str::after($what, ' ')) ?: __('an outside service')]),
            'file' => (string) __(match ($what) {
                'delete' => 'Deleted a file',
                'move' => $failed ? 'Could not move a file' : 'Moved a file',
                default => $failed ? 'Could not store a file' : 'Stored a file',
            }),
            'rollback' => (string) __('Put the save back'),
            default => null,
        };
    }

    /**
     * Say a write to the database: "Saved a new booking".
     */
    protected function write(string $sql): ?string
    {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $sql, $match) !== 1) {
            return null;
        }

        // A write is about the table it names first. An upsert names its
        // columns after "update" too, which are not tables.
        preg_match('/\b(?:into|update|from)\s+[`"\[]?([A-Za-z0-9_]+)/i', $sql, $target);
        $table = strtolower($target[1] ?? '');

        if ($table === '' || in_array($table, self::FRAMEWORK_TABLES, true)) {
            return null;
        }

        $thing = str_replace('_', ' ', Str::singular($table));

        return (string) __(match (strtolower($match[1])) {
            'insert' => 'Saved a new :thing',
            'update' => 'Changed a :thing',
            default => 'Deleted a :thing',
        }, ['thing' => $thing]);
    }

    /**
     * Find every table of the app a query touches, the main one first.
     *
     * @return list<string>
     */
    protected function tables(string $sql): array
    {
        preg_match_all('/\b(?:into|update|from|join)\s+[`"\[]?([A-Za-z0-9_]+)[`"\]]?/i', $sql, $matches);

        return array_values(array_filter(array_unique(array_map(strtolower(...), $matches[1])), fn (string $table) => ! in_array($table, self::FRAMEWORK_TABLES, true)));
    }

    /**
     * Say a class name in words: "App\Mail\BookingConfirmedMail" is "Booking confirmed".
     *
     * @param  list<string>  $endings
     */
    protected function name(string $class, array $endings = []): string
    {
        $base = class_basename(str_replace('/', '\\', $class));

        foreach ($endings as $ending) {
            $base = (string) preg_replace('/'.$ending.'$/', '', $base);
        }

        $words = Str::ucfirst(Str::lower(Str::headline($base)));

        return $words === '' ? $class : $words;
    }
}
