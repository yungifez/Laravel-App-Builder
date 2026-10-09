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
        Schema::table('cleared_problems', function (Blueprint $table) {
            // The owner said failing is fine while something is down, so
            // the problem stays put away even when it happens again.
            $table->boolean('fine')->default(false)->after('problem');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cleared_problems', function (Blueprint $table) {
            $table->dropColumn('fine');
        });
    }
};
