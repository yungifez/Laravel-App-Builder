<?php

namespace App\Actions\Decisions;

use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use Illuminate\Support\Str;

class ObserveOutcome
{
    /**
     * A change touching at most this many files, without a repair, was
     * trivial; one touching at least SUBSTANTIAL_FILES, or repaired twice,
     * was substantial. Tests are not counted.
     */
    protected const TRIVIAL_FILES = 2;

    protected const SUBSTANTIAL_FILES = 8;

    /**
     * Say what each decision should have been, from what actually happened
     * to the request (the final diff and its repairs), or null for those
     * that cannot be known yet: the request is still being built, or it
     * stopped without a result.
     *
     * @return array<string, string|null>
     */
    public function handle(FeatureRequest $featureRequest): array
    {
        if ($featureRequest->status === FeatureRequestStatus::Answered) {
            return ['question' => 'yes', 'complexity' => null, 'permissions' => 'no', 'persisted_data' => 'no', 'destructive' => 'no'];
        }

        if ($featureRequest->status !== FeatureRequestStatus::Generated || blank($featureRequest->patch)) {
            return [];
        }

        $files = $this->files((string) $featureRequest->patch);
        $added = $this->addedLines((string) $featureRequest->patch);
        $code = array_values(array_filter($files, fn (string $file) => ! Str::startsWith($file, 'tests/')));
        $repairs = (int) $featureRequest->latestRun?->repairs;

        return [
            'question' => 'no',
            'complexity' => match (true) {
                count($code) >= self::SUBSTANTIAL_FILES || $repairs >= 2 => 'substantial',
                count($code) <= self::TRIVIAL_FILES && $repairs === 0 => 'trivial',
                default => 'normal',
            },
            'permissions' => $this->yes(
                collect($files)->contains(fn (string $file) => Str::startsWith($file, ['app/Policies/', 'app/Http/Middleware/']))
                || collect($added)->flatten()->contains(fn (string $line) => preg_match('/Gate::|->authorize\(|->can\(|[\'"]can:/', $line) === 1),
            ),
            'persisted_data' => $this->yes(collect($files)->contains(fn (string $file) => Str::startsWith($file, 'database/migrations/'))),
            'destructive' => $this->yes(collect($added)->contains(fn (array $lines, string $file) => collect($this->forwardLines($file, $lines))
                ->contains(fn (string $line) => preg_match('/->(forceDelete|truncate|delete)\(|->drop\w*\(|Schema::drop/', $line) === 1))),
        ];
    }

    /**
     * @return list<string>
     */
    protected function files(string $patch): array
    {
        preg_match_all('#^diff --git a/(\S+) b/#m', $patch, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Get the added lines of each file.
     *
     * @return array<string, list<string>>
     */
    protected function addedLines(string $patch): array
    {
        $lines = [];
        $file = null;

        foreach (explode("\n", $patch) as $line) {
            if (preg_match('#^\+\+\+ b/(\S+)#', $line, $match) === 1) {
                $file = $match[1];
            } elseif ($file !== null && str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $lines[$file][] = substr($line, 1);
            }
        }

        return $lines;
    }

    /**
     * Leave out a migration's down() method: every new table has a drop
     * there, which undoes the change rather than deleting anything.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    protected function forwardLines(string $file, array $lines): array
    {
        if (! Str::startsWith($file, 'database/migrations/')) {
            return $lines;
        }

        $down = collect($lines)->search(fn (string $line) => str_contains($line, 'function down('));

        return $down === false ? $lines : array_slice($lines, 0, $down);
    }

    protected function yes(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
