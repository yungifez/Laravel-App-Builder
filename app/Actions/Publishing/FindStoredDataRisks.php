<?php

namespace App\Actions\Publishing;

use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Support\Str;

/**
 * Say, in the owner's words, how a change would touch information the live
 * app already keeps: deleting it, renaming it, changing how it is kept or
 * rewriting it. Read from the database migrations the change adds, only
 * what runs going forward, so an undo step is never mistaken for a risk.
 * A pattern match, not a guarantee: it names what it sees and nothing more.
 */
class FindStoredDataRisks
{
    /**
     * What each kind of risk looks like in a migration's up() method.
     *
     * @var array<string, string>
     */
    protected const PATTERNS = [
        'deletes' => '/Schema::drop(IfExists)?\s*\(|->drop(Column|Columns|Morphs|Timestamps|SoftDeletes|RememberToken)\s*\(|->(delete|truncate)\s*\(|DB::delete\s*\(/',
        'renames' => '/->renameColumn\s*\(|Schema::rename\s*\(/',
        'reshapes' => '/->change\s*\(\s*\)/',
        'rewrites' => '/->update\s*\(|DB::(update|statement|unprepared)\s*\(/',
    ];

    public function __construct(private ProjectRepository $repository) {}

    /**
     * Get the kinds of risk a commit's new migrations carry, in a fixed
     * order: deletes, renames, reshapes, rewrites.
     *
     * @return list<string>
     */
    public function handle(Project $project, string $commit): array
    {
        $result = $this->repository->git($project, ['diff-tree', '--no-commit-id', '--name-only', '-r', '--root', '--diff-filter=A', $commit], throw: false, timeout: 10);

        if (! $result->successful()) {
            return [];
        }

        $found = [];

        foreach (array_filter(explode("\n", trim($result->output()))) as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $code = (string) $this->repository->show($project, $commit, $path);

            // A migration is known by what it is, not where it lives.
            if (! str_contains($code, 'extends Migration')) {
                continue;
            }

            $up = Str::before(Str::after($code, 'function up('), 'function down(');

            foreach (self::PATTERNS as $kind => $pattern) {
                if (preg_match($pattern, $up) === 1) {
                    $found[$kind] = true;
                }
            }
        }

        return array_values(array_filter(array_keys(self::PATTERNS), fn (string $kind) => isset($found[$kind])));
    }
}
