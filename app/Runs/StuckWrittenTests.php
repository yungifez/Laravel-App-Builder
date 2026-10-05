<?php

namespace App\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Features\TestReport;
use App\Models\Run;
use App\Models\Verification;

/**
 * Find a test written before the change that may itself be wrong (§12).
 * The coder may not change a written test, so a wrong one would use up
 * every try and stop a change whose code is right. Nothing here asks a
 * model.
 */
class StuckWrittenTests
{
    /**
     * Get the written tests that failed the same way on this check and on
     * the one before it, while every other test and check passed. The code
     * changed in between and only these held it back. A test that fails a
     * different way each time is left alone: the code is still moving.
     *
     * @return list<array{item: int, file: string, name: string, message: string}>
     */
    public function in(Run $run, Verification $verification): array
    {
        $plan = $run->plan === null ? null : Plan::fromArray($run->plan);

        if ($plan === null || $plan->writtenTests === []) {
            return [];
        }

        $now = $this->failing($verification, $plan);
        $before = $run->verifications()->where('id', '<', $verification->id)->where('interrupted', false)->reorder('id', 'desc')->first();
        $then = $now === null || $before === null ? null : $this->failing($before, $plan);

        if ($now === null || $then === null) {
            return [];
        }

        return array_values(array_filter($now, fn (array $test) => in_array($test, $then, true)));
    }

    /**
     * Get the written tests a check failed, with what each said, or null
     * when anything else failed too or nothing names a failed test.
     *
     * @return list<array{item: int, file: string, name: string, message: string}>|null
     */
    protected function failing(Verification $verification, Plan $plan): ?array
    {
        $failed = [];

        foreach ($verification->results ?? [] as $result) {
            // The same problems the app had before the change are not the change's.
            if (! in_array($result['outcome'], ['failed', 'errored'], true)
                || CompleteRunVerification::lookupCouldNotRun($result)
                || (($result['at_start'] ?? null) === 'failed' && ($result['new_problems'] ?? []) === [])) {
                continue;
            }

            if (! isset($result['tests']) || $result['timed_out']) {
                return null;
            }

            foreach ($result['tests'] as $test) {
                if ($test['outcome'] !== TestReport::FAILED) {
                    continue;
                }

                $written = collect($plan->writtenTests)->first(fn (array $written) => TestReport::same($test, $written['file'], $written['name']));

                if ($written === null) {
                    return null;
                }

                // Each data set of a test is one case in the report; the first speaks for it.
                $failed["{$written['file']}|{$written['name']}"] ??= [...$written, 'message' => (string) ($test['message'] ?? '')];
            }
        }

        return $failed === [] ? null : array_values($failed);
    }
}
