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
        Schema::table('test_observations', function (Blueprint $table) {
            // Per code file, the lines each test ran, as ranges.
            $table->json('lines')->nullable()->after('files');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('test_observations', function (Blueprint $table) {
            $table->dropColumn('lines');
        });
    }
};
