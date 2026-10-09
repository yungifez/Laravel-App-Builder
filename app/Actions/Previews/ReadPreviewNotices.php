<?php

namespace App\Actions\Previews;

use App\Models\Preview;
use App\Models\Project;
use App\Previews\LoggedEmails;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ReadPreviewNotices
{
    /**
     * How many notices are shown, the newest first.
     */
    public const LIMIT = 50;

    /**
     * Names in a notice whose values are never shown.
     */
    protected const HIDDEN = '/password|token|secret|api_key|private_key/i';

    /**
     * Names in a notice that hold what it is called, and what it says.
     */
    protected const TITLES = ['title', 'subject', 'heading'];

    protected const WORDS = ['message', 'body', 'text', 'line', 'description', 'content'];

    /**
     * How long a shown value is, at most.
     */
    protected const SHOWN = 500;

    /**
     * Read the notices through the app itself: what Laravel's database
     * channel keeps for each person, whatever the app calls its table.
     * Only the limit is passed in; the code run is always the same.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $model = Illuminate\Notifications\DatabaseNotification::class;
        if (! Illuminate\Support\Facades\Schema::hasTable((new $model)->getTable())) { exit(3); }
        $notices = $model::query()->latest()->limit((int) $argv[1])->get();
        echo json_encode($notices->map(function ($notice) {
            $person = rescue(fn () => $notice->notifiable, null, false);
            return [
                'id' => (string) $notice->getKey(),
                'type' => (string) $notice->type,
                'data' => $notice->data,
                'read' => $notice->read_at !== null,
                'sent_at' => $notice->created_at?->toIso8601String(),
                'name' => isset($person->name) && is_string($person->name) ? $person->name : null,
                'email' => isset($person->email) && is_string($person->email) ? $person->email : null,
            ];
        })->values(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Get the notices the app on show left for people inside the app, such
     * as what its bell shows, newest first. They are not email: a person
     * reads them when they sign in. The owner reads them here, beside the
     * email, without signing in as each person.
     *
     * @return list<array{id: string, sent_at: string|null, to: string, subject: string, text: string, read: bool}>
     */
    public function handle(Project $project): array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview === null) {
            return [];
        }

        $output = (string) Cache::remember("previews:{$preview->id}:notices", now()->addSeconds(4), fn () => rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', '-r', self::SCRIPT, '--', (string) self::LIMIT], 60, ''),
            '',
            report: false,
        ));
        $notices = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '[')), true);
        // A notice the owner deleted from the list is marked in the app's log, as an email is.
        $deleted = LoggedEmails::deleted($this->readPreviewLog->handle($preview));

        return array_values(array_filter(array_map(
            fn ($notice) => is_array($notice) && is_string($notice['id'] ?? null) ? $this->shown($preview, $notice) : null,
            is_array($notices) ? $notices : [],
        ), fn (?array $notice) => $notice !== null && ! isset($deleted[$notice['id']])));
    }

    /**
     * Say one notice as the owner reads it: who it is for, what it is
     * called and what it says.
     *
     * @param  array<array-key, mixed>  $notice
     * @return array{id: string, sent_at: string|null, to: string, subject: string, text: string, read: bool}
     */
    protected function shown(Preview $preview, array $notice): array
    {
        $data = is_array($notice['data'] ?? null) ? $notice['data'] : [];
        $name = is_string($notice['name'] ?? null) ? $notice['name'] : null;
        $email = is_string($notice['email'] ?? null) ? $notice['email'] : null;
        $title = collect(self::TITLES)->map(fn (string $key) => $data[$key] ?? null)->first(fn ($value) => is_string($value) && trim($value) !== '');
        $words = collect(self::WORDS)->map(fn (string $key) => $data[$key] ?? null)->first(fn ($value) => is_string($value) && trim($value) !== '');

        // What else the notice holds, one line each. A path in the app is
        // written as its whole address, so the owner can follow it.
        $rest = collect($data)
            ->except([...self::TITLES, ...self::WORDS])
            ->filter(fn ($value, $key) => is_scalar($value) && $value !== '' && preg_match(self::HIDDEN, (string) $key) !== 1)
            // An address is called a link, whatever the app calls it.
            ->map(fn ($value, $key) => (preg_match('/url|link|href/i', (string) $key) === 1 ? __('Link') : Str::ucfirst(Str::lower(Str::headline((string) $key)))).': '.$this->value($preview, $value))
            ->values();

        return [
            // The same kind of name an email has, so both are deleted the same way.
            'id' => sha1("notice\n{$notice['id']}"),
            'sent_at' => is_string($notice['sent_at'] ?? null) ? $notice['sent_at'] : null,
            // A name is enough to tell who: the line stays short on a phone.
            'to' => $name ?? $email ?? __('Someone'),
            // A notice with no title is called by its kind, in words.
            'subject' => Str::limit(is_string($title) ? trim($title) : $this->kind(is_string($notice['type'] ?? null) ? $notice['type'] : ''), 120),
            'text' => collect([is_string($words) ? Str::limit(trim($words), self::SHOWN) : null, $rest->implode("\n")])->filter()->implode("\n\n"),
            'read' => ($notice['read'] ?? false) === true,
        ];
    }

    /**
     * Say the kind of a notice in words, from the name the app gave it:
     * "App\Notifications\OrderShippedNotification" is "Order shipped".
     */
    protected function kind(string $type): string
    {
        $words = Str::ucfirst(Str::lower(Str::headline((string) preg_replace('/Notification$/', '', class_basename($type)))));

        return $words === '' ? (string) __('Notice') : $words;
    }

    /**
     * Show one value of a notice as a short line.
     */
    protected function value(Preview $preview, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('yes') : __('no');
        }

        $text = (string) $value;

        return str_starts_with($text, '/') && ! str_starts_with($text, '//')
            ? rtrim($preview->url(), '/').$text
            : Str::limit($text, self::SHOWN);
    }
}
