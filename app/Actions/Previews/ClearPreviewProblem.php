<?php

namespace App\Actions\Previews;

use App\Actions\Context\RecordDecision;
use App\Models\ClearedProblem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Take a problem off the app's list, or put it back. A problem met while
 * something was down on purpose asks the owner whether the app should
 * cope. Their answer is a product decision, so it goes into the notes,
 * where later changes follow it.
 */
class ClearPreviewProblem
{
    /**
     * The answer that says the app need not cope.
     */
    public const FINE = 'No, failing is fine here.';

    /**
     * The answer that says the app should cope.
     */
    public const COPE = 'Yes, it should cope.';

    public function __construct(private ReadPreviewProblems $readProblems, private RecordDecision $recordDecision) {}

    /**
     * Ask the owner's question about something being down, in their words.
     */
    public static function question(string $during): string
    {
        return __('Should your app keep working when :what?', ['what' => self::outage($during)]);
    }

    /**
     * Name what was down as it reads inside a sentence: "email is down".
     */
    public static function outage(string $during): string
    {
        return Str::lcfirst(ReadPreviewHappenings::FAULTS[$during] ?? $during);
    }

    /**
     * Clear a problem. Saying failing is fine is kept as a decision, and
     * only means something for a problem met while something was down.
     */
    public function handle(Project $project, User $owner, string $problemId, bool $fine = false): ClearedProblem
    {
        // What was down is read from the app again, not taken from the page.
        $during = $fine ? collect($this->readProblems->handle($project))->firstWhere('id', $problemId)['during'] ?? null : null;

        $clearance = $project->clearedProblems()->updateOrCreate(
            ['problem' => $problemId],
            ['user_id' => $owner->id, 'fine' => $during !== null, 'during' => $during, 'cleared_at' => now()],
        );

        if ($during !== null) {
            $this->recordDecision->handle($project, self::question($during), self::FINE);
        }

        return $clearance;
    }

    /**
     * Put a cleared problem back in the list, and take back the decision
     * that failing there is fine.
     */
    public function restore(Project $project, string $problemId): void
    {
        $clearance = $project->clearedProblems()->where('problem', $problemId)->first();

        if ($clearance === null) {
            return;
        }

        $clearance->delete();

        if ($clearance->fine && $clearance->during !== null) {
            $this->recordDecision->forget($project, self::question($clearance->during), self::FINE);
        }
    }
}
