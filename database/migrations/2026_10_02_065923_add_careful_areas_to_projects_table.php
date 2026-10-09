<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The parts of the app the owner asked to be extra careful with
        // (strict mode, direction 33). Kept here, not in the app's notes,
        // because the agent can write the notes.
        Schema::table('projects', function (Blueprint $table) {
            $table->json('careful_areas')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('careful_areas');
        });
    }
};
