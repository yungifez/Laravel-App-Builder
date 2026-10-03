<?php

namespace App\Actions\Context;

use App\Actions\Runs\RecordModelUsage;
use App\Ai\Agents\NotesDrafter;
use App\Enums\ModelRole;
use App\Jobs\DraftProjectNotes;
use App\Models\Project;
use App\Projects\ProjectRepository;

class EstimateExploration
{
    /**
     * The most a draft of the notes is expected to take to write.
     */
    protected const OUTPUT_TOKENS = 4_000;

    /**
     * A token is about this many characters of English or code.
     */
    protected const CHARS_PER_TOKEN = 4;

    public function __construct(private ProjectRepository $repository) {}

    /**
     * Estimate what exploring the app costs, before the owner starts it:
     * what the drafter reads (the outline, and the facts at their most)
     * and writes. The cost is null when the planner's model has no price.
     *
     * @return array{tokens: int, cost_usd: float|null}
     */
    public function handle(Project $project): array
    {
        $head = $this->repository->head($project);
        $files = DraftProjectNotes::appFiles($this->repository, $project, $head);
        $chars = mb_strlen((string) (new NotesDrafter)->instructions())
            + mb_strlen(DraftProjectNotes::outline($this->repository, $project, $head, $files))
            + GatherAppFacts::MAX_CHARS;
        $input = (int) ceil($chars / self::CHARS_PER_TOKEN);
        $model = ModelRole::Planner->model();

        return [
            // Rounded up, since the owner reads it as a most.
            'tokens' => (int) (ceil(($input + self::OUTPUT_TOKENS) / 1000) * 1000),
            'cost_usd' => $model === null ? null : RecordModelUsage::cost($model, $input, self::OUTPUT_TOKENS),
        ];
    }
}
