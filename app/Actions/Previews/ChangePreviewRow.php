<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

class ChangePreviewRow
{
    /**
     * Change one value of one row through the app itself, by the one
     * column that names the row. The table, row, column and value are
     * passed in, as arguments; the code run is always the same. A value
     * of the wrong kind for its column, or one the database refuses, is
     * told, not failed on.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        [, $table, $row, $column, $empty, $value] = $argv;
        $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) { exit(3); }
        $primary = collect($schema->getIndexes($table))->firstWhere('primary', true);
        if ($primary === null || count($primary['columns']) !== 1 || $primary['columns'][0] === $column) { exit(3); }
        // Some databases keep any text in any column; the app would then
        // fail to read it back, so the kind of value is checked here too.
        $type = strtolower($schema->getColumnType($table, $column));
        $fits = $empty === '1' || match (true) {
            (bool) preg_match('/date|time/', $type) => strtotime($value) !== false,
            (bool) preg_match('/bool/', $type) => in_array(strtolower($value), ['0', '1', 'true', 'false'], true),
            (bool) preg_match('/^(tiny|small|medium|big)?int(eger|[248])?\b|serial/', $type) => (bool) preg_match('/^-?\d+$/', $value),
            (bool) preg_match('/numeric|decimal|real|double|float/', $type) => is_numeric($value),
            default => true,
        };
        if (! $fits) {
            echo json_encode(['changed' => 0, 'refused' => true]);
            exit(0);
        }
        try {
            $changed = Illuminate\Support\Facades\DB::table($table)->where($primary['columns'][0], $row)->update([$column => $empty === '1' ? null : $value]);
        } catch (Illuminate\Database\QueryException $e) {
            if (! in_array(substr((string) $e->getCode(), 0, 2), ['22', '23'], true)) { throw $e; }
            echo json_encode(['changed' => 0, 'refused' => true]);
            exit(0);
        }
        echo json_encode(['changed' => $changed, 'refused' => false]);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private ReadPreviewData $readPreviewData, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Change one value of one row the app on show saved. An empty value
     * is saved as nothing.
     *
     * @throws ValidationException when the app does not run, has no such
     *                             table, or refuses the value.
     */
    public function handle(Project $project, string $table, string $row, string $column, ?string $value): void
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);

        if (collect($this->readPreviewData->handle($project) ?? [])->firstWhere('name', $table) === null) {
            throw ValidationException::withMessages(['table' => __('Your app does not keep that data any more.')]);
        }

        $output = $this->runPreviewCommand->handle(
            $preview,
            ['php', '-r', self::SCRIPT, '--', $table, $row, $column, $value === null ? '1' : '0', $value ?? ''],
            60,
            __('Your app could not change it. This is our fault. Try again.'),
        );
        $result = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        if (is_array($result) && ($result['refused'] ?? false) === true) {
            throw ValidationException::withMessages(['value' => $value === null
                ? __('Your app needs something here.')
                : __('Your app cannot save that here. Check it is the right kind, such as a number or a date.')]);
        }
    }
}
