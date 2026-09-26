<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Jobs\StartPreview;
use App\Models\Preview;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RequestProjectPreview
{
    public function __construct(
        private StopPreview $stopPreview,
        private ProjectRepository $repository,
    ) {}

    /**
     * Start an editable preview of the project as it is now (its latest
     * commit), replacing any running one.
     *
     * @throws ValidationException when the project cannot be read.
     */
    public function handle(Project $project): Preview
    {
        try {
            $this->repository->import($project);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['preview' => $exception->getMessage()]);
        }

        $project->previews()
            ->whereNull('feature_request_id')
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->each(fn (Preview $preview) => $this->stopPreview->handle($preview));

        return DB::transaction(function () use ($project) {
            $preview = $project->previews()->create([
                'revision' => $this->repository->head($project),
                'editable' => true,
                'host' => 'p'.Str::lower(Str::random(31)),
                'status' => PreviewStatus::Starting,
                'expires_at' => now()->addMinutes((int) config('builder.preview.max_minutes')),
            ]);

            StartPreview::dispatch($preview)->afterCommit();

            return $preview;
        });
    }
}
