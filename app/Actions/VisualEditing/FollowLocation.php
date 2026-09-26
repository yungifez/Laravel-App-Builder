<?php

namespace App\Actions\VisualEditing;

use App\Models\Project;
use App\Projects\ProjectRepository;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateElement;

class FollowLocation
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Find where an element written at a place in one version of the app is
     * in a later version. The running app stamps where it was built from;
     * after a save, the newest version can be ahead of it while it
     * rebuilds. Following the element through the lines that changed lets
     * the owner keep editing in the meantime.
     *
     * Returns null when the element's own lines were rewritten in a way
     * that cannot be followed, so nothing is edited by mistake.
     */
    public function handle(Project $project, string $from, string $to, SourceLocation $location): ?SourceLocation
    {
        if ($from === $to) {
            return $location;
        }

        $before = $this->repository->show($project, $from, $location->file);
        $after = $this->repository->show($project, $to, $location->file);

        if ($before === null || $after === null) {
            return null;
        }

        if ($before === $after) {
            return $location;
        }

        $element = TemplateElement::at($before, $location->line, $location->column);
        $line = $element === null ? null : self::line($this->hunks($project, $from, $to, $location->file), $location->line);

        if ($element === null || $line === null) {
            return null;
        }

        $followed = TemplateElement::at($after, $line, $location->column);

        return $followed !== null && $followed->tag === $element->tag
            ? new SourceLocation($location->file, $line, $location->column, $location->instance)
            : null;
    }

    /**
     * Get where a line moved to through a diff's hunks, as [old start, old
     * count, new start, new count]. A line inside a hunk that kept its line
     * count (a line rewritten in place, such as new classes) keeps its
     * place in the hunk; one inside a hunk that added or removed lines
     * cannot be followed.
     *
     * @param  list<array{int, int, int, int}>  $hunks
     */
    public static function line(array $hunks, int $line): ?int
    {
        $shift = 0;

        foreach ($hunks as [$oldStart, $oldCount, $newStart, $newCount]) {
            // Lines only added go after "old start".
            $first = $oldCount === 0 ? $oldStart + 1 : $oldStart;

            if ($line < $first) {
                break;
            }

            if ($line < $oldStart + $oldCount) {
                return $oldCount === $newCount ? $newStart + ($line - $oldStart) : null;
            }

            $shift += $newCount - $oldCount;
        }

        return $line + $shift;
    }

    /**
     * @return list<array{int, int, int, int}>
     */
    protected function hunks(Project $project, string $from, string $to, string $file): array
    {
        $diff = $this->repository->git($project, ['diff', '-U0', '--no-color', '--no-ext-diff', $from, $to, '--', $file])->output();

        preg_match_all('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/m', $diff, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [
            (int) $match[1],
            $match[2] === '' ? 1 : (int) $match[2],
            (int) $match[3],
            ($match[4] ?? '') === '' ? 1 : (int) $match[4],
        ], $matches);
    }
}
