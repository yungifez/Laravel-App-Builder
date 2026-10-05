<?php

namespace App\Actions\Features;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectWrittenCase
{
    public function __construct(private RetryFeatureRequest $retryFeatureRequest) {}

    /**
     * Determine if the owner may still say a case tested before the build is
     * not what they meant: the change was made from tests written first, it
     * is not kept or undone, and it was not tried again.
     *
     * The tests are written and frozen before the coder starts, so asking
     * the owner first would stop every change. The owner confirms them
     * after, on the made change, and a correction makes it again.
     */
    public static function open(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        return $run !== null
            && $run->status === RunStatus::Completed
            && ($run->plan['written_tests'] ?? []) !== []
            && $featureRequest->status === FeatureRequestStatus::Generated
            && $featureRequest->commit_sha === null
            && $featureRequest->reverted_at === null
            && ! FeatureRequest::query()->where('retry_of_id', $featureRequest->id)->exists();
    }

    /**
     * List the cases that have a test written before the build, by the
     * acceptance criterion's number (from 1) and the case's kind.
     *
     * @return list<array{criterion: int, kind: string, says: string}>
     */
    public static function written(FeatureRequest $featureRequest): array
    {
        $plan = $featureRequest->latestRun?->plan;

        if ($plan === null || $plan['written_tests'] === []) {
            return [];
        }

        $items = array_column($plan['written_tests'], 'item');
        $cases = array_values(array_filter(
            $plan['cases'],
            fn (array $case) => $case['says'] !== null && isset($plan['acceptance_criteria'][$case['criterion'] - 1]),
        ));

        // The written tests are numbered as Plan::verifyItems() lists the
        // cases: every case that says something, in the plan's order.
        return array_values(array_map(
            fn (array $case) => ['criterion' => $case['criterion'], 'kind' => $case['kind'], 'says' => (string) $case['says']],
            array_filter($cases, fn (array $case, int $index) => in_array($index + 1, $items, true), ARRAY_FILTER_USE_BOTH),
        ));
    }

    /**
     * Make the change again without a case the owner did not mean. The new
     * try goes the way trying again goes (RetryFeatureRequest::rebuild),
     * and its run starts with the owner's answer about the case, so the
     * planner plans it again from their words and the tests are written
     * from that plan. The answer is one more than the questions the
     * planner may ask, and the run records why.
     *
     * @throws ValidationException when the change can no longer be corrected or has no such case.
     */
    public function handle(FeatureRequest $featureRequest, User $owner, int $criterion, string $kind, string $note): FeatureRequest
    {
        if (! self::open($featureRequest)) {
            throw ValidationException::withMessages(['note' => __('This change can no longer be made again from here.')]);
        }

        $case = collect(self::written($featureRequest))->first(fn (array $case) => $case['criterion'] === $criterion && $case['kind'] === $kind);

        if ($case === null) {
            throw ValidationException::withMessages(['note' => __('Pick one of the things this change was checked for.')]);
        }

        return DB::transaction(function () use ($featureRequest, $owner, $case, $note) {
            $retry = $this->retryFeatureRequest->rebuild($featureRequest, $owner);
            $run = $retry->latestRun;
            $criterion = $featureRequest->latestRun->plan['acceptance_criteria'][$case['criterion'] - 1];

            $run->update([
                'answers' => [['question' => __('Is this what you meant: “:case”?', ['case' => "{$criterion}: {$case['says']}"]), 'answer' => __('No. :note', ['note' => $note]), 'decided_by' => 'owner']],
                'question_limit' => $run->question_limit + 1,
            ]);
            $run->recordEvent('case_corrected', ['change' => $featureRequest->uuid, 'criterion' => $case['criterion'], 'kind' => $case['kind'], 'note' => $note]);

            return $retry;
        });
    }
}
