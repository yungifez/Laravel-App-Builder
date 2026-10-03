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
            // What was down on purpose, so the owner's decision about it can
            // be taken back when they show the problem again.
            $table->string('during')->nullable()->after('fine');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cleared_problems', function (Blueprint $table) {
            $table->dropColumn('during');
        });
    }
};
