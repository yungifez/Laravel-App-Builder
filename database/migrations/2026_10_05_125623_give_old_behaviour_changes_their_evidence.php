<?php

use App\Context\ChangeClassification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each behaviour change in a review now says what backs it. Reviews
     * saved before that get it from their own saved classification.
     */
    public function up(): void
    {
        DB::table('runs')->whereNotNull('review')->orderBy('id')->select(['id', 'review'])->each(function (object $run) {
            $review = json_decode($run->review, true);
            $classification = ChangeClassification::fromArray($review['classification']);
            $changes = array_map(
                fn (array $change) => array_key_exists('evidence', $change) ? $change : [...$change, 'evidence' => $classification->evidenceFor($change['area'])],
                $review['changes'],
            );

            if ($changes !== $review['changes']) {
                DB::table('runs')->where('id', $run->id)->update(['review' => json_encode([...$review, 'changes' => $changes])]);
            }
        });
    }

    /**
     * A one-off fix to saved data: there is nothing to undo.
     */
    public function down(): void {}
};
