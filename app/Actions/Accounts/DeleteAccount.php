<?php

namespace App\Actions\Accounts;

use App\Actions\Billing\EndPlan;
use App\Jobs\ForgetProjectFiles;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeleteAccount
{
    public function __construct(private EndPlan $endPlan) {}

    /**
     * Delete a person and everything of theirs, as the privacy page
     * promises: the database removes their apps' rows with them, and a job
     * per app removes the files kept outside it. The files are listed
     * before the rows go, because only the rows say where they are.
     *
     * @throws ValidationException when the plan could not be stopped.
     */
    public function handle(User $user): void
    {
        $this->stopPaying($user);

        DB::transaction(function () use ($user) {
            $jobs = $user->projects()->pluck('id')->map(fn (int $id) => new ForgetProjectFiles($id, $this->shots($id)));

            $user->delete();

            $jobs->each(fn (ForgetProjectFiles $job) => dispatch($job)->afterCommit());
        });
    }

    /**
     * Stop a paid plan before anything is deleted. When it cannot be
     * stopped, nothing is, so nobody pays for an account that is gone. A
     * plan already stopped is left alone, so this may run more than once.
     *
     * @throws ValidationException when the plan could not be stopped.
     */
    public function stopPaying(User $user): void
    {
        try {
            $this->endPlan->handle($user);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['account' => __('This is our fault: we could not stop your paid plan, so your account was not deleted. Please try again in a few minutes.')]);
        }
    }

    /**
     * The screenshots the checks took of a project's changes.
     *
     * @return list<string>
     */
    protected function shots(int $projectId): array
    {
        return array_values(Verification::query()
            ->whereIn('feature_request_id', FeatureRequest::query()->where('project_id', $projectId)->select('id'))
            ->pluck('screens')
            ->flatMap(fn (?array $screens) => array_column($screens['shots'] ?? [], 'path'))
            ->map(fn (mixed $path) => (string) $path)
            ->all());
    }
}
