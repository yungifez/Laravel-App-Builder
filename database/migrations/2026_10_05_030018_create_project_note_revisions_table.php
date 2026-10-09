<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_note_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('branch');
            $table->string('path');
            // What the file said after this write; null when it was removed.
            $table->text('contents')->nullable();
            // Who made the write, when a person did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'branch', 'path']);
        });

        // The history starts with the notes as they are now.
        DB::table('project_note_revisions')->insertUsing(
            ['project_id', 'branch', 'path', 'contents', 'created_at', 'updated_at'],
            DB::table('project_notes')->select('project_id', 'branch', 'path', 'contents', 'updated_at', 'updated_at'),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_note_revisions');
    }
};
