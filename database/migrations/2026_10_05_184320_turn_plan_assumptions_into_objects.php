<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each assumption a saved plan holds as text becomes an object that
     * touches nothing, can be undone and is no easier to judge once seen.
     */
    public function up(): void
    {
        $this->rewrite(fn (mixed $assumption) => is_string($assumption)
            ? ['text' => $assumption, 'touches' => [], 'reversible' => true, 'easier_after_seeing' => false]
            : $assumption);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->rewrite(fn (mixed $assumption) => is_array($assumption) ? $assumption['text'] : $assumption);
    }

    /**
     * Rewrite each assumption of every saved plan.
     */
    protected function rewrite(Closure $assumption): void
    {
        DB::table('runs')->whereNotNull('plan')->orderBy('id')->chunkById(200, function ($runs) use ($assumption) {
            foreach ($runs as $run) {
                $plan = json_decode($run->plan, true);

                if (! is_array($plan) || ! is_array($plan['assumptions'] ?? null)) {
                    continue;
                }

                $plan['assumptions'] = array_map($assumption, $plan['assumptions']);

                DB::table('runs')->where('id', $run->id)->update(['plan' => json_encode($plan)]);
            }
        });
    }
};
