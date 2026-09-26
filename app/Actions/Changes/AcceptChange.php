<?php

namespace App\Actions\Changes;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptChange
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Commit a built, verified and reviewed change to the project's
     * repository. A follow-up is committed together with the changes it
     * builds on that are not accepted yet, as one commit.
     *
     * @throws ValidationException when the change cannot be accepted.
     */
    public function handle(FeatureRequest $featureRequest, User $owner): FeatureRequest
    {
        $run = $featureRequest->latestRun;

        if ($featureRequest->status !== FeatureRequestStatus::Generated || $run?->status !== RunStatus::Completed) {
            throw ValidationException::withMessages(['change' => __('Only a change whose run completed can be accepted.')]);
        }

        if ($featureRequest->commit_sha !== null) {
            throw ValidationException::withMessages(['change' => __('This change was accepted already.')]);
        }

        $project = $featureRequest->project;
        $this->repository->import($project);

        $lineage = $featureRequest->lineage();
        $pending = array_values(array_filter($lineage, fn (FeatureRequest $request) => $request->commit_sha === null));
        $accepted = array_values(array_filter($lineage, fn (FeatureRequest $request) => $request->commit_sha !== null));
        $base = $accepted !== [] ? (string) end($accepted)->commit_sha : ($featureRequest->base_revision ?? $this->repository->root($project));

        try {
            $sha = $this->repository->commitPatches(
                $project,
                $base,
                array_map(fn (FeatureRequest $request) => (string) $request->patch, $pending),
                $this->message($featureRequest, $pending),
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['change' => $exception->getMessage().' '.__('Ask for it again to build it on the current project.')]);
        }

        DB::transaction(function () use ($pending, $sha, $run, $featureRequest) {
            foreach ($pending as $request) {
                $request->update(['commit_sha' => $sha, 'accepted_at' => now()]);
            }

            $run->recordEvent('change_accepted', [
                'commit' => $sha,
                'requests' => array_map(fn (FeatureRequest $request) => $request->id, $pending),
                'feature_request_id' => $featureRequest->id,
            ]);
        });

        return $featureRequest->refresh();
    }

    /**
     * Describe the change in the commit message: the owner's request, the
     * summary of the change, and the requests it holds.
     *
     * @param  list<FeatureRequest>  $requests
     */
    protected function message(FeatureRequest $featureRequest, array $requests): string
    {
        $lines = [Str::limit(Str::squish($featureRequest->prompt), 70), ''];

        if (filled($featureRequest->summary)) {
            $lines[] = wordwrap((string) $featureRequest->summary, 72);
            $lines[] = '';
        }

        foreach ($requests as $request) {
            $lines[] = "Builder-Request: #{$request->id}";
        }

        return implode("\n", $lines);
    }
}
