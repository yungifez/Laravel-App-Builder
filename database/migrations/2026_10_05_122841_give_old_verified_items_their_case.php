<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each verified item now proves one case of a criterion. Items written
     * before cases existed checked only the usual way, so they become that
     * case, worded as their criterion.
     */
    public function up(): void
    {
        DB::table('runs')->whereNotNull('review')->orderBy('id')->select(['id', 'review'])->each(function (object $run) {
            $review = json_decode($run->review, true);
            $verified = array_map(
                fn (array $item) => array_key_exists('kind', $item) ? $item : [...$item, 'kind' => 'base', 'case' => $item['criterion']],
                $review['verified'],
            );

            if ($verified !== $review['verified']) {
                DB::table('runs')->where('id', $run->id)->update(['review' => json_encode([...$review, 'verified' => $verified])]);
            }
        });
    }

    /**
     * A one-off fix to saved data: there is nothing to undo.
     */
    public function down(): void {}
};
