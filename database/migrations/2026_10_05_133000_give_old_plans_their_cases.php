<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every plan now lists the base, alternate and exception cases its tests
     * must check, and the tests written for them before the change. Plans
     * made before that named no cases and had no tests written first, so
     * they say so with empty lists.
     */
    public function up(): void
    {
        DB::table('runs')->whereNotNull('plan')->orderBy('id')->select(['id', 'plan'])->each(function (object $run) {
            $plan = json_decode($run->plan, true);
            $missing = array_diff_key(['cases' => [], 'written_tests' => [], 'written_files' => []], $plan);

            if ($missing !== []) {
                DB::table('runs')->where('id', $run->id)->update(['plan' => json_encode([...$plan, ...$missing])]);
            }
        });
    }

    public function down(): void
    {
        //
    }
};
