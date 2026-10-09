<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the element the owner pointed at, when a request
     * starts from a selection in the preview.
     */
    public function up(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->json('selection')->nullable()->after('prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->dropColumn('selection');
        });
    }
};
