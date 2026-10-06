<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Project;

class CountRowsFailingFormat
{
    /**
     * Run a format's stricter rule over one column through the app itself,
     * and count. Only the names and settings are passed in, as arguments;
     * the code run is always the same, writes nothing and prints only the
     * two counts, never a saved value.
     */
    protected const SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        [$table, $column, $kind] = [$argv[1], $argv[2], $argv[3]];
        $accepts = array_values(array_filter(explode(',', $argv[4])));
        $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) { exit(3); }
        $rule = match ($kind) {
            'phone' => 'phone:'.implode(',', $accepts),
            'url' => 'url:'.implode(',', $accepts),
            'postal_code' => new App\Rules\ValidPostalCode($accepts),
            'isbn' => new App\Rules\ValidIsbn(array_map('intval', $accepts)),
        };
        $rows = 0;
        $failing = 0;
        foreach (Illuminate\Support\Facades\DB::table($table)->whereNotNull($column)->select($column)->cursor() as $row) {
            $rows++;
            $failing += Illuminate\Support\Facades\Validator::make(['value' => $row->{$column}], ['value' => [$rule]])->fails() ? 1 : 0;
        }
        echo json_encode(['rows' => $rows, 'failing' => $failing]);
        PHP;

    public function __construct(private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Count the saved rows of the owner's app on show that the stricter
     * format refuses. Null when the app is not running or the rows could
     * not be read.
     *
     * @param  list<string>  $accepts  What the stricter rule accepts: countries, ISBN lengths or schemes
     * @return array{rows: int, failing: int}|null
     */
    public function handle(Project $project, string $table, string $column, string $kind, array $accepts): ?array
    {
        $preview = $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        if ($preview?->status !== PreviewStatus::Ready || $preview->workspace === null) {
            return null;
        }

        $output = (string) rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', '-r', self::SCRIPT, '--', $table, $column, $kind, implode(',', $accepts)], 120, ''),
            '',
            report: false,
        );
        $data = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '{')), true);

        return is_array($data) && is_int($data['rows'] ?? null) && is_int($data['failing'] ?? null)
            ? ['rows' => $data['rows'], 'failing' => $data['failing']]
            : null;
    }
}
