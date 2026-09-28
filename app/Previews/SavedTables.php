<?php

namespace App\Previews;

use Illuminate\Support\Str;

/**
 * The tables an app keeps its data in, read from what `db:show --json
 * --counts` prints. Tables Laravel keeps for itself are marked, so the
 * owner sees their own data first.
 */
class SavedTables
{
    /**
     * Tables every Laravel app may have for its own work, not the owner's.
     */
    protected const LARAVEL = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens', 'personal_access_tokens', 'notifications',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
        'pulse_values', 'pulse_entries', 'pulse_aggregates', 'sqlite_sequence',
    ];

    /**
     * Read the tables, the owner's own first, each in name order.
     *
     * @return list<array{name: string, words: string, rows: int|null, own: bool}>
     */
    public static function in(string $output): array
    {
        // Notices a package prints come before the JSON line.
        $line = collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{'));
        $data = json_decode((string) $line, true);
        $tables = is_array($data) && is_array($data['tables'] ?? null) ? $data['tables'] : [];

        return array_values(collect($tables)
            ->filter(fn ($table) => is_array($table) && is_string($table['table'] ?? null))
            ->map(fn (array $table) => [
                'name' => (string) $table['table'],
                'words' => Str::of((string) $table['table'])->headline()->lower()->ucfirst()->toString(),
                'rows' => is_int($table['rows'] ?? null) ? $table['rows'] : null,
                'own' => ! in_array($table['table'], self::LARAVEL, true),
            ])
            ->sortBy([['own', 'desc'], ['name', 'asc']])
            ->all());
    }
}
