<?php

namespace App\Runs;

use App\Features\PatchSummary;
use Illuminate\Support\Str;

/**
 * Find where a change renames or drops the app's working tables and
 * columns, or edits a migration that already ran, when the plan did not
 * ask for it. A coder bending the schema to fit a written test that
 * guessed a name breaks everything else that uses it (WriteBrief::
 * TEST_WRONG). Read from the patch alone; nothing here asks a model.
 */
class ReshapedSchema
{
    /**
     * Get what the change reshapes that the plan did not ask for, one
     * plain line each.
     *
     * @return list<string>
     */
    public function in(?string $patch, Plan $plan): array
    {
        $files = array_values(array_filter(PatchSummary::files($patch), fn (array $file) => str_starts_with($file['path'], 'database/migrations/')));
        $asked = Str::lower(implode("\n", [$plan->summary, ...$plan->tasks, ...$plan->acceptanceCriteria, ...array_map(fn (array $step) => "{$step['file']} {$step['label']} {$step['detail']}", $plan->steps)]));
        $created = [];
        $found = [];

        foreach ($files as $file) {
            if (preg_match_all('/Schema::create\(\s*[\'"](\w+)[\'"]/', $this->added($file['diff']), $matches) > 0) {
                array_push($created, ...$matches[1]);
            }
        }

        foreach ($files as $file) {
            if (! preg_match('/^new file mode/m', $file['diff'])) {
                if (! str_contains($asked, Str::lower($file['path']))) {
                    $found[] = __('It changes :file, a migration that already ran.', ['file' => $file['path']]);
                }

                continue;
            }

            // Only what the migration does; its down() undoes it.
            $up = Str::before($this->added($file['diff']), 'function down');

            foreach ([
                '/renameColumn\(\s*[\'"](\w+)[\'"]/' => 'It renames the :name column.',
                '/dropColumn\(\s*\[?\s*[\'"](\w+)[\'"]/' => 'It removes the :name column.',
                '/Schema::rename\(\s*[\'"](\w+)[\'"]/' => 'It renames the :name table.',
                '/Schema::drop(?:IfExists)?\(\s*[\'"](\w+)[\'"]/' => 'It removes the :name table.',
            ] as $pattern => $line) {
                preg_match_all($pattern, $up, $matches);

                foreach ($matches[1] as $name) {
                    $table = str_contains($line, 'table');

                    // A table made by this change is the change's own.
                    if (($table && in_array($name, $created, true)) || $this->asked($asked, $name)) {
                        continue;
                    }

                    $found[] = __($line, ['name' => $name]);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Determine if the plan asks to rename or remove this name.
     */
    protected function asked(string $plan, string $name): bool
    {
        $name = Str::lower($name);

        return (str_contains($plan, $name) || str_contains($plan, str_replace('_', ' ', $name)))
            && preg_match('/\b(renam|drop|remov|delet)/', $plan) === 1;
    }

    /**
     * Get the lines a file's diff adds.
     */
    protected function added(string $diff): string
    {
        return implode("\n", array_map(
            fn (string $line) => substr($line, 1),
            array_filter(explode("\n", $diff), fn (string $line) => str_starts_with($line, '+') && ! str_starts_with($line, '+++')),
        ));
    }
}
