<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\RunEvent;
use Illuminate\Support\Str;

class SelectAreas
{
    /**
     * Areas taken from the words of the request, at most. More would load
     * most of the notes, which is what choosing areas avoids.
     */
    public const NAMED_LIMIT = 3;

    /**
     * Why an area was chosen, strongest first.
     */
    public const STEP = 'step';

    public const PICKED = 'picked';

    public const FOLLOW_UP = 'follow_up';

    public const NAMED = 'named';

    public const PLANNER = 'planner';

    /**
     * Choose the areas of the app a change is about from evidence alone,
     * so the same request in the same app always loads the same notes
     * (§26.3). The planner's own choice is added after these, by the
     * caller, and marked as its own.
     *
     * In order: the files of the step the owner pointed at, the file of
     * the element they picked in the app, the areas of the change this one
     * follows up on (those it was given and those its agent read), then
     * areas whose name or behaviours the request names.
     *
     * @return array<string, string> The reason for each area, by key
     */
    public function handle(ProjectContext $context, FeatureRequest $featureRequest): array
    {
        $chosen = [];
        $parent = $featureRequest->parent;
        $step = $parent !== null && $featureRequest->target_step !== null ? $parent->step($featureRequest->target_step) : null;

        if ($step !== null) {
            $chosen += array_fill_keys($context->claiming($step['file']), self::STEP);
        }

        if (filled($featureRequest->selection['file'] ?? null)) {
            $chosen += array_fill_keys($context->claiming($featureRequest->selection['file']), self::PICKED);
        }

        if ($parent !== null && $parent->latestRun !== null) {
            /** @var list<string> $targets */
            $targets = $parent->latestRun->context['targets'] ?? [];
            $read = $parent->latestRun->events()->where('type', 'areas_read')->get()
                ->flatMap(fn (RunEvent $event) => array_keys($event->data['areas'] ?? []))->map(strval(...))->all();
            $chosen += array_fill_keys($context->known([...$targets, ...$read]), self::FOLLOW_UP);
        }

        return $chosen + array_fill_keys($this->named($context, $featureRequest->prompt), self::NAMED);
    }

    /**
     * Get the other areas the agent read while it worked: their notes, or
     * code they claim. Reading is how the agent asks for an area it was not
     * given, so the run records it, and a follow-up starts with it.
     *
     * @param  list<string>  $targets  The areas the agent was given
     * @param  list<string>  $read  The files it read, from the workspace root
     * @return array<string, string> The first file read of each area, by key
     */
    public function read(ProjectContext $context, array $targets, array $read): array
    {
        $notes = ProjectNotes::directory().'/'.ProjectContext::CAPABILITIES_DIRECTORY.'/';
        $areas = [];

        foreach ($read as $file) {
            $keys = str_starts_with($file, $notes) && str_ends_with($file, '.md')
                ? $context->known([substr($file, strlen($notes), -3)])
                : $context->claiming($file);

            foreach (array_diff($keys, $targets) as $key) {
                $areas[$key] ??= $file;
            }
        }

        return $areas;
    }

    /**
     * Get the areas the request names: all the words of an area's name, or
     * of one of its behaviours, appear in it. Words are compared by their
     * stem, so "book a room" names "Room bookings". The areas matching the
     * most words come first.
     *
     * @return list<string>
     */
    protected function named(ProjectContext $context, string $request): array
    {
        $words = $this->stems($request);
        $scores = [];

        foreach ($context->capabilities as $key => $capability) {
            foreach ($this->names($capability) as $name) {
                $stems = $this->stems($name);

                if ($stems !== [] && array_diff($stems, $words) === []) {
                    $scores[$key] = max($scores[$key] ?? 0, count($stems));
                }
            }
        }

        uksort($scores, fn (string $a, string $b) => [$scores[$b], $a] <=> [$scores[$a], $b]);

        return array_slice(array_map(strval(...), array_keys($scores)), 0, self::NAMED_LIMIT);
    }

    /**
     * Get the names an area answers to: its name, its key, its behaviours.
     *
     * @return list<string>
     */
    protected function names(Capability $capability): array
    {
        return [$capability->name, str_replace(['-', '_'], ' ', $capability->key), ...array_column($capability->behaviors, 'name')];
    }

    /**
     * Get the stems of a text's words of three letters or more.
     *
     * @return list<string>
     */
    protected function stems(string $text): array
    {
        preg_match_all('/[a-z0-9]{3,}/', Str::lower(Str::ascii($text)), $words);

        return array_values(array_unique(array_map(
            fn (string $word) => (string) preg_replace(['/ies$/', '/(ings|ing)$/', '/(\w{3})ed$/', '/([^s])s$/'], ['y', '', '$1', '$1'], $word),
            $words[0],
        )));
    }
}
