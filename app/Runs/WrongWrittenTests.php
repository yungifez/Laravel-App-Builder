<?php

namespace App\Runs;

use Illuminate\Support\Str;

/**
 * Find the written tests the coder says are wrong (WriteBrief::TEST_WRONG),
 * so each is checked against the app and corrected at once, rather than
 * the coder bending working code to fit a guess. Only a test the plan
 * wrote counts, named exactly. Nothing here asks a model.
 */
class WrongWrittenTests
{
    /**
     * Get the written tests the coder's reply names as wrong, with its
     * reason, each once.
     *
     * @return list<array{item: int, file: string, name: string, message: string, by: string}>
     */
    public function reported(string $reply, Plan $plan): array
    {
        if ($plan->writtenTests === [] || preg_match_all('/^\W*TEST WRONG\s+(.+)$/m', $reply, $lines) === 0) {
            return [];
        }

        $reported = [];

        foreach ($lines[1] as $line) {
            foreach ($plan->writtenTests as $test) {
                $named = "{$test['file']} :: {$test['name']}:";

                if (str_starts_with(trim($line), $named)) {
                    $reported["{$test['file']}|{$test['name']}"] ??= [...$test, 'message' => Str::limit(trim(Str::after(trim($line), $named)), 2000), 'by' => 'coder'];
                }
            }
        }

        return array_values($reported);
    }
}
