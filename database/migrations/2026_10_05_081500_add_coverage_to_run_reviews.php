<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every review now says how well each touched part is tested. Reviews
     * written before that measured nothing, so they say so with an empty
     * list.
     */
    public function up(): void
    {
        DB::table('runs')->whereNotNull('review')->orderBy('id')->select(['id', 'review'])->each(function (object $run) {
            $review = json_decode($run->review, true);

            if (! array_key_exists('coverage', $review)) {
                DB::table('runs')->where('id', $run->id)->update(['review' => json_encode([...$review, 'coverage' => []])]);
            }
        });
    }

    public function down(): void
    {
        DB::table('runs')->whereNotNull('review')->orderBy('id')->select(['id', 'review'])->each(function (object $run) {
            $review = json_decode($run->review, true);
            unset($review['coverage']);

            DB::table('runs')->where('id', $run->id)->update(['review' => json_encode($review)]);
        });
    }
};
