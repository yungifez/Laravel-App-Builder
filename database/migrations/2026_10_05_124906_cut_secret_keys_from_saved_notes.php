<?php

use App\Support\Secrets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Notes are now saved with live keys cut out (§27.9). Cut them from the
     * notes and the history saved before, too: a key left in an old row
     * is still a leak. Each row cut is logged by note, never with the key.
     */
    public function up(): void
    {
        foreach (['project_notes', 'project_note_revisions'] as $table) {
            DB::table($table)->whereNotNull('contents')->orderBy('id')->select(['id', 'project_id', 'branch', 'path', 'contents'])->each(function (object $note) use ($table) {
                if (! Secrets::found($note->contents)) {
                    return;
                }

                DB::table($table)->where('id', $note->id)->update(['contents' => Secrets::redact($note->contents)]);
                Log::warning('A secret key was cut from a saved note.', ['table' => $table, 'project' => $note->project_id, 'branch' => $note->branch, 'path' => $note->path]);
            });
        }
    }

    /**
     * A one-off fix to saved data: the keys are gone, so there is nothing
     * to undo.
     */
    public function down(): void {}
};
