<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each review now lists the touched areas whose notes the change did
     * not rewrite. Reviews saved before that work it out from their own
     * classification and the area files in the run's saved outline.
     */
    public function up(): void
    {
        DB::table('runs')->whereNotNull('review')->orderBy('id')->select(['id', 'review', 'context'])->each(function (object $run) {
            $review = json_decode($run->review, true);
            $classification = $review['classification'];

            if (array_key_exists('notes_behind', $classification)) {
                return;
            }

            $files = array_column(json_decode((string) $run->context, true)['outline'] ?? [], 'file', 'key');
            $touched = array_unique([...array_keys($classification['requested']), ...array_keys($classification['may_also_affect']), ...array_keys($classification['unexpected'])]);
            $behind = array_values(array_filter($touched, fn (int|string $key) => ! in_array($files[$key] ?? null, $classification['context_updates'], true)));
            sort($behind);

            DB::table('runs')->where('id', $run->id)->update(['review' => json_encode([...$review, 'classification' => [...$classification, 'notes_behind' => $behind]])]);
        });
    }

    /**
     * A one-off fix to saved data: there is nothing to undo.
     */
    public function down(): void {}
};
