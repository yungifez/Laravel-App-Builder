<?php

namespace App\Actions\Context;

use App\Context\ProjectContext;
use App\Features\TestMap;
use App\Features\UnsafeCode;
use App\Models\Project;
use App\Models\TestObservation;
use App\Projects\Frontend;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class CheckProjectNotes
{
    public function __construct(private ProjectRepository $repository, private ReadProjectContext $readProjectContext) {}

    /**
     * Compare the notes of the line the owner works in with the code at the
     * tip of its branch and list the obvious problems, in the owner's words. No model is involved: every finding
     * is a fact about the files, with the files it is about as its details.
     *
     * A finding the notes alone can put right also says how: "fix" names
     * the part of the notes and the items to take out of it.
     *
     * @return list<array{title: string, details: list<string>, fix?: array{part: string, remove: list<string>}}>
     */
    public function handle(Project $project): array
    {
        $head = $this->repository->head($project);
        $files = $this->repository->files($project, $head);
        $context = $this->readProjectContext->current($project);
        $findings = [];
        $secrets = UnsafeCode::secretFiles($files);

        // The one safety fact a file list proves on its own, so it is said first.
        if ($secrets !== []) {
            $findings[] = ['title' => __('Secret settings are saved in the app\'s code, where anyone with the code can read them.'), 'details' => $secrets];
        }

        if ($context->problems !== []) {
            $findings[] = ['title' => __('Some notes could not be read, so I cannot use them.'), 'details' => $context->problems];
        }

        if ($context->project === null) {
            $findings[] = ['title' => __('There is no description of what your app is for yet.'), 'details' => []];
        }

        foreach ($context->capabilities as $capability) {
            $missing = array_values(array_filter($capability->paths, fn (string $pattern) => ! $this->matchesAny($pattern, $files)));

            if ($missing !== []) {
                $findings[] = ['title' => __('The notes on ":name" point to files that are not in the app.', ['name' => $capability->name]), 'details' => $missing, 'fix' => ['part' => "paths:{$capability->key}", 'remove' => $missing]];
            }

            $unknown = array_values(array_filter(array_map(fn ($effect) => $effect->to, $capability->effects), fn (string $key) => ! isset($context->capabilities[$key])));

            if ($unknown !== []) {
                $findings[] = ['title' => __('":name" says it is connected to something the notes do not describe.', ['name' => $capability->name]), 'details' => $unknown, 'fix' => ['part' => "effects:{$capability->key}", 'remove' => $unknown]];
            }

            if ($capability->testFiles === []) {
                $findings[] = ['title' => __('Nothing checks ":name" automatically.', ['name' => $capability->name]), 'details' => [__('No test for it runs with the checks.')]];
            }
        }

        $unlisted = $this->unlistedBehaviors($context, TestObservation::latestFor($project)?->map());

        // Putting it right adds to the notes rather than taking out, so no
        // fix button: the title says where the owner writes it instead.
        if ($unlisted !== []) {
            $findings[] = ['title' => __('Your app\'s tests check things the notes do not describe. Copy each one into the rules of the part it belongs to, below.'), 'details' => $unlisted];
        }

        // The app's own screens are described too, wherever its frontend keeps them.
        $described = array_values(array_filter([...Config::array('builder.context.described_paths'), ...Frontend::of($this->repository, $project, $head)->pages], is_string(...)));
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
     * Get what the tests check for behaviours they name that no area's notes
     * list, in the tests' own words. A test names the behaviour it proves
     * with a `behavior:<key>` group; when the notes lose that key, the test
     * still proves it but nothing describes it. A rule that says what the
     * test checks describes it too, so copying the sentence into a part's
     * rules clears it.
     *
     * @return list<string>
     */
    protected function unlistedBehaviors(ProjectContext $context, ?TestMap $map): array
    {
        if ($map === null) {
            return [];
        }

        $listed = [];
        $rules = [];

        foreach ($context->capabilities as $capability) {
            foreach ($capability->behaviors as $behavior) {
                $listed[$behavior['key']] = true;
            }

            foreach ($capability->rules() as $rule) {
                $rules[] = self::plain($rule);
            }
        }

        $sentences = [];

        foreach ($map->tests as $index => $test) {
            foreach ($test['groups'] as $group) {
                if (str_starts_with($group, TestMap::BEHAVIOR_GROUP) && ! isset($listed[Str::after($group, TestMap::BEHAVIOR_GROUP)])) {
                    $sentence = $map->sentence($index);

                    if (! Str::contains(implode("\n", $rules), self::plain($sentence))) {
                        $sentences[] = $sentence;
                    }

                    break;
                }
            }
        }

        $sentences = array_values(array_unique($sentences));
        sort($sentences);

        return $sentences;
    }

    /**
     * Compare sentences as people copy them: any case, spacing or full stop.
     */
    protected static function plain(string $text): string
    {
        return rtrim(Str::lower(Str::squish($text)), '.');
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
