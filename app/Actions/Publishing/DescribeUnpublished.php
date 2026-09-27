<?php

namespace App\Actions\Publishing;

use App\Enums\DeploymentStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Projects\ProjectRepository;

/**
 * Say what putting the newest version online would change, in the owner's
 * words: the requests kept since the online version, the ones undone
 * since, and how many look edits were made by hand. Version history turned
 * into product history (direction 18 §9); commits are never named.
 */
class DescribeUnpublished
{
    public function __construct(private ProjectRepository $repository, private FindStoredDataRisks $findStoredDataRisks) {}

    /**
     * Describe what is kept but not online yet, or null when nothing is
     * online, the newest version is, or the history cannot tell. With
     * $risks, each added request also says how it would touch information
     * the live app keeps; the owner decides with that in front of them.
     *
     * @return array{added: list<array{id: int, asked: string, data?: list<string>}>, undone: list<array{id: int, asked: string}>, edits: int}|null
     */
    public function handle(Project $project, ?string $head, bool $risks = false): ?array
    {
        $live = $project->deployments()->where('status', DeploymentStatus::Published)->latest('id')->value('commit_sha');

        if ($head === null || $live === null || $live === $head) {
            return null;
        }

        $result = $this->repository->git($project, ['rev-list', "{$live}..{$head}"], throw: false, timeout: 10);

        if (! $result->successful()) {
            return null;
        }

        $commits = array_values(array_filter(explode("\n", trim($result->output()))));

        // A request kept and undone since the online version changes nothing
        // online, so it is left out of both lists.
        $added = $project->featureRequests()->whereIn('commit_sha', $commits)->whereNull('reverted_at')->orderBy('accepted_at')->get();
        $undone = $project->featureRequests()->whereIn('revert_sha', $commits)->whereNotIn('commit_sha', $commits)->orderBy('reverted_at')->get();

        $asked = fn (FeatureRequest $featureRequest) => ['id' => $featureRequest->id, 'asked' => $featureRequest->prompt];

        return [
            'added' => array_values($added->map(fn (FeatureRequest $featureRequest) => $risks
                ? [...$asked($featureRequest), 'data' => $this->findStoredDataRisks->handle($project, (string) $featureRequest->commit_sha)]
                : $asked($featureRequest))->all()),
            'undone' => array_values($undone->map($asked)->all()),
            'edits' => $project->visualEdits()->whereIn('commit_sha', $commits)->whereNull('reverted_at')->count(),
        ];
    }
}
