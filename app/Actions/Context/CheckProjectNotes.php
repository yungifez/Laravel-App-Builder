<?php

namespace App\Actions\Context;

use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class CheckProjectNotes
{
    public function __construct(private ProjectRepository $repository, private ReadProjectContext $readProjectContext) {}

    /**
     * Compare the notes with the code at a revision and list the obvious
     * problems, in the owner's words. No model is involved: every finding
     * is a fact about the files, with the files it is about as its details.
     *
     * @return list<array{title: string, details: list<string>}>
     */
    public function handle(Project $project, string $revision): array
    {
        $files = $this->repository->files($project, $revision);
        $context = $this->readProjectContext->atRevision($project, $revision);
        $findings = [];

        if ($context->problems !== []) {
            $findings[] = ['title' => __('Some notes could not be read, so I cannot use them.'), 'details' => $context->problems];
        }

        if ($context->project === null) {
            $findings[] = ['title' => __('There is no description of what your app is for yet.'), 'details' => []];
        }

        foreach ($context->capabilities as $capability) {
            $missing = array_values(array_filter($capability->paths, fn (string $pattern) => ! $this->matchesAny($pattern, $files)));

            if ($missing !== []) {
                $findings[] = ['title' => __('The notes on ":name" point to files that are not in the app.', ['name' => $capability->name]), 'details' => $missing];
            }

            $unknown = array_values(array_filter(array_map(fn ($effect) => $effect->to, $capability->effects), fn (string $key) => ! isset($context->capabilities[$key])));

            if ($unknown !== []) {
                $findings[] = ['title' => __('":name" says it is connected to something the notes do not describe.', ['name' => $capability->name]), 'details' => $unknown];
            }

            if ($capability->testFiles === []) {
                $findings[] = ['title' => __('Nothing checks ":name" automatically.', ['name' => $capability->name]), 'details' => [__('No test for it runs with the checks.')]];
            }
        }

        $described = array_values(array_filter(Config::array('builder.context.described_paths'), is_string(...)));
        $undescribed = array_values(array_filter(Config::array('builder.context.undescribed'), is_string(...)));

        $unclaimed = array_values(array_filter($files, fn (string $path) => Str::startsWith($path, $described)
            && ! Str::is($undescribed, $path)
            && $context->claiming($path) === []));

        if ($unclaimed !== []) {
            $findings[] = ['title' => __('Some parts of the app are not described in any notes.'), 'details' => $unclaimed];
        }

        return $findings;
    }

    /**
     * Determine if a path pattern matches any of the files.
     *
     * @param  list<string>  $files
     */
    protected function matchesAny(string $pattern, array $files): bool
    {
        foreach ($files as $file) {
            if (Str::is($pattern, $file)) {
                return true;
            }
        }

        return false;
    }
}
