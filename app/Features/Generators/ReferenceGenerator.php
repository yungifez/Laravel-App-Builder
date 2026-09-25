<?php

namespace App\Features\Generators;

use App\Features\Contracts\FeatureGenerator;
use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\GeneratedChange;
use App\Models\FeatureRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Answers feature requests with known-good solutions from a manifest.
 *
 * Each manifest solution names a patch file, keywords a request must mention,
 * the steps it exposes, and, for follow-ups, the solution and step it builds on.
 * It stands in for the AI agent so the whole owner flow can run end to end.
 */
class ReferenceGenerator implements FeatureGenerator
{
    public function __construct(protected ?string $path) {}

    public function generate(FeatureRequest $request): GeneratedChange
    {
        $solution = $this->classify($request);

        if ($solution === null) {
            throw new CannotGenerateFeature(__('The reference generator has no solution for this request.'));
        }

        $patchPath = $this->directory().DIRECTORY_SEPARATOR.$solution['patch'];

        if (! File::isFile($patchPath)) {
            throw new CannotGenerateFeature(__('The reference patch [:patch] is missing.', ['patch' => $solution['patch']]));
        }

        return new GeneratedChange(
            solutionKey: $solution['key'],
            summary: $solution['summary'],
            patch: File::get($patchPath),
            steps: $solution['steps'],
            acceptance: $solution['acceptance'] ?? [],
        );
    }

    /**
     * Find the manifest solution that answers the request: a top-level
     * solution whose keywords the prompt mentions, or for a follow-up, the
     * solution that follows the parent's and matches the prompt (and, when
     * $matchStep is true, the selected step).
     *
     * @return array{key: string, patch: string, match: list<string>, summary: string, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance?: list<string>, follows?: string, step?: string}|null
     */
    public function classify(FeatureRequest $request, bool $matchStep = true): ?array
    {
        $parent = $request->parent;

        return $this->solutions()->first(fn (array $solution) => $parent === null
            ? ! isset($solution['follows']) && $this->matches($solution, $request->prompt)
            : ($solution['follows'] ?? null) === $parent->solution_key
                && (! $matchStep || ($solution['step'] ?? null) === $request->target_step)
                && $this->matches($solution, $request->prompt));
    }

    /**
     * Load the solutions listed in the manifest.
     *
     * @return Collection<int, array{key: string, patch: string, match: list<string>, summary: string, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance?: list<string>, follows?: string, step?: string}>
     */
    protected function solutions(): Collection
    {
        $manifest = $this->directory().DIRECTORY_SEPARATOR.'manifest.json';

        if (! File::isFile($manifest)) {
            throw new CannotGenerateFeature(__('The reference solutions manifest was not found.'));
        }

        /** @var array{solutions: list<array{key: string, patch: string, match: list<string>, summary: string, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance?: list<string>, follows?: string, step?: string}>} $data */
        $data = File::json($manifest, JSON_THROW_ON_ERROR);

        return collect($data['solutions']);
    }

    /**
     * Get the configured directory, resolving relative paths from the base path.
     */
    protected function directory(): string
    {
        if (blank($this->path)) {
            throw new CannotGenerateFeature(__('No reference solutions directory is configured (BUILDER_REFERENCE_SOLUTIONS).'));
        }

        return Str::startsWith($this->path, DIRECTORY_SEPARATOR) ? $this->path : base_path($this->path);
    }

    /**
     * Determine if the prompt mentions any of the solution's keywords.
     *
     * @param  array{match: list<string>}  $solution
     */
    protected function matches(array $solution, string $prompt): bool
    {
        return Str::contains($prompt, $solution['match'], ignoreCase: true);
    }
}
