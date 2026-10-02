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
        // The most work per request each area of the app may do, by area
        // key, set when a change is kept (a drift measure, direction 33).
        // Kept here, not in the app, because the agent can write the app.
        Schema::table('projects', function (Blueprint $table) {
            $table->json('drift_ceilings')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('drift_ceilings');
        });
    }
};
