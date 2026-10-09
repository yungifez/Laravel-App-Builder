<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

class DeletePreviewRow
{
    /**
     * Delete one row through the app itself, by the one column that names
     * it. The table and the row are passed in, as arguments; the code run
     * is always the same. A row other rows still point to is kept, and
     * the app says so instead of failing.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
        if (! $schema->hasTable($argv[1])) { exit(3); }
        $primary = collect($schema->getIndexes($argv[1]))->firstWhere('primary', true);
        if ($primary === null || count($primary['columns']) !== 1) { exit(3); }
        try {
            $deleted = Illuminate\Support\Facades\DB::table($argv[1])->where($primary['columns'][0], $argv[2])->delete();
        } catch (Illuminate\Database\QueryException $e) {
            if (! str_starts_with((string) $e->getCode(), '23')) { throw $e; }
            echo json_encode(['deleted' => 0, 'linked' => true]);
            exit(0);
        }
        echo json_encode(['deleted' => $deleted, 'linked' => false]);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private ReadPreviewData $readPreviewData, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Delete one row the app on show saved.
     *
     * @throws ValidationException when the app does not run, has no such
     *                             table, or keeps the row.
     */
    public function handle(Project $project, string $table, string $row): void
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);

        if (collect($this->readPreviewData->handle($project) ?? [])->firstWhere('name', $table) === null) {
            throw ValidationException::withMessages(['table' => __('Your app does not keep that data any more.')]);
        }

        $output = $this->runPreviewCommand->handle(
            $preview,
            ['php', '-r', self::SCRIPT, '--', $table, $row],
            60,
            __('Your app could not delete it. This is our fault. Try again.'),
        );
        $result = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        ReadPreviewData::forget($preview);

        if (is_array($result) && ($result['linked'] ?? false) === true) {
            throw ValidationException::withMessages(['app' => __('Your app kept it, because other saved data still points to it. Delete that first.')]);
        }
    }
}
