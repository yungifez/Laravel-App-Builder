<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Illuminate\Support\Str;

class ReadPreviewRows
{
    /**
     * The rows shown of one table, the newest first when it can tell.
     */
    public const LIMIT = 50;

    /**
     * Columns whose values are never shown: what a visitor signs in with,
     * and keys the app keeps.
     */
    public const HIDDEN = '/password|token|secret|remember|two_factor|api_key|private_key/i';

    /**
     * How long a shown value is, at most.
     */
    protected const SHOWN = 200;

    /**
     * Read the rows through the app itself, as it saved them. Only the
     * name is passed in, as an argument; the code run is always the same.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $table = $argv[1];
        $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
        if (! $schema->hasTable($table)) { exit(3); }
        $columns = $schema->getColumnListing($table);
        $query = Illuminate\Support\Facades\DB::table($table)->limit((int) $argv[2] + 1);
        foreach (['id', 'created_at'] as $newest) { if (in_array($newest, $columns, true)) { $query->orderByDesc($newest); break; } }
        $primary = collect($schema->getIndexes($table))->firstWhere('primary', true);
        $key = $primary !== null && count($primary['columns']) === 1 ? $primary['columns'][0] : null;
        echo json_encode(['columns' => $columns, 'key' => $key, 'rows' => $query->get()], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private ReadPreviewData $readPreviewData, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Get the rows of one table the app on show saved, or null when it is
     * not one of its tables or the app does not run.
     *
     * @return array{name: string, words: string, columns: list<string>, key: string|null, ids: list<string|null>, changeable: list<string>, cut: list<list<int>>, rows: list<list<string|null>>, more: bool}|null
     */
    public function handle(Project $project, string $table): ?array
    {
        $preview = $this->readPreviewLog->preview($project);
        $known = collect($this->readPreviewData->handle($project) ?? [])->firstWhere('name', $table);

        if ($preview === null || $known === null) {
            return null;
        }

        $output = (string) rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', '-r', self::SCRIPT, '--', $table, (string) self::LIMIT], 60, ''),
            '',
            report: false,
        );
        $data = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        /** @var list<string> $columns */
        $columns = array_values(array_filter(is_array($data) ? (array) ($data['columns'] ?? []) : [], is_string(...)));
        $rows = is_array($data) ? (array) ($data['rows'] ?? []) : [];
        $more = count($rows) > self::LIMIT;
        // A row can be deleted when the table names it by one column.
        $key = is_array($data) && is_string($data['key'] ?? null) && in_array($data['key'], $columns, true) ? $data['key'] : null;

        $rows = array_slice($rows, 0, self::LIMIT);

        return [
            'name' => $table,
            'words' => $known['words'],
            'columns' => $columns,
            'key' => $key,
            'ids' => array_values(array_map(
                fn ($row) => $key !== null && is_array($row) && is_scalar($row[$key] ?? null) ? (string) $row[$key] : null,
                $rows,
            )),
            // A value can be changed in a row that can be named, but never
            // the name itself or what is hidden.
            'changeable' => $key === null ? [] : array_values(array_filter($columns, fn (string $column) => $column !== $key && preg_match(self::HIDDEN, $column) !== 1)),
            // Values shown cut short, by place, are changed only in full.
            'cut' => array_values(array_map(
                fn ($row) => array_keys(array_filter($columns, fn (string $column) => is_array($row) && mb_strwidth($this->text($row[$column] ?? null) ?? '', 'UTF-8') > self::SHOWN)),
                $rows,
            )),
            'rows' => array_values(array_map(
                fn ($row) => array_map(fn (string $column) => $this->shown($column, is_array($row) ? ($row[$column] ?? null) : null), $columns),
                $rows,
            )),
            'more' => $more,
        ];
    }

    /**
     * Show a value as a short line of text, or hide it.
     */
    protected function shown(string $column, mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        if (preg_match(self::HIDDEN, $column) === 1) {
            return '••••••';
        }

        return Str::limit($text, self::SHOWN);
    }

    /**
     * A value as the text it holds.
     */
    protected function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : (string) json_encode($value);
    }
}
