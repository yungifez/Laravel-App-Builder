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
        Schema::table('feature_requests', function (Blueprint $table) {
            // The stopped request the owner asked to try again, so the
            // retry counts as a human intervention (architecture §31.4).
            $table->foreignId('retry_of_id')->nullable()->after('parent_id')->constrained('feature_requests')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retry_of_id');
        });
    }
};
