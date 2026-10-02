<?php

namespace App\Actions\Previews;

use App\Models\Project;
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
    ];

    /**
     * Tables the framework keeps for itself. Reading or writing them is
     * not something the owner's app did.
     */
    protected const FRAMEWORK_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens', 'telescope_entries', 'pulse_entries'];

    public function __construct(private ReadPreviewLog $readPreviewLog, private WorkspaceManager $workspaces) {}

    /**
     * Get what the app on show did behind its last pages, newest first, in
     * plain words: what it saved, sent, stored and asked. And which kind of
     * thing the owner has made fail, if any.
     *
     * @return array{fault: string, requests: list<array{id: string, page: string, status: int, outcome: string|null, did: list<array{text: string, failed: bool}>}>}
     */
    public function handle(Project $project): array
    {
        $preview = $this->readPreviewLog->preview($project);
        $workspace = $preview?->workspace;

        if ($preview === null || $workspace === null || ! Config::boolean('builder.preview.recorder.enabled')) {
            return ['fault' => 'none', 'requests' => []];
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

        $lines = array_values(array_filter(explode("\n", $trace), fn (string $line) => str_starts_with($line, '{')));
        $requests = [];

        foreach (array_reverse(array_slice($lines, -self::LIMIT)) as $at => $line) {
            $operation = json_decode($line, true);

            if (is_array($operation) && is_int($operation['status'] ?? null)) {
                $requests[] = $this->request($operation, count($lines) - $at);
            }
        }

        return [
            'fault' => is_array($fault) && isset(self::FAULTS[$fault['kind'] ?? '']) ? $fault['kind'] : 'none',
            'requests' => $requests,
        ];
    }

    /**
     * Say one request as the owner reads it.
     *
     * @param  array<string, mixed>  $operation
     * @return array{id: string, page: string, status: int, outcome: string|null, did: list<array{text: string, failed: bool}>}
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
            'did' => $did,
        ];
    }

    /**
     * Say the page a request was for: "Opened /bookings", "Sent the form on /bookings".
     */
    protected function page(string $method, string $route): string
    {
        $route = Str::before($route, '#');
        $path = $route === '' ? __('a page') : $route;

        return match (true) {
            $method === 'ARTISAN' => (string) __('Ran a task: :name', ['name' => $route]),
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
            'notification' => (string) __('Left a notice: :name', ['name' => $this->name($what, ['Notification'])]),
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

        $table = $this->table($sql);

        if ($table === null) {
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
     * Find the table a query is about.
     */
    protected function table(string $sql): ?string
    {
        return $this->tables($sql)[0] ?? null;
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
